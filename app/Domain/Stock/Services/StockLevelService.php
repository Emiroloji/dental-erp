<?php

namespace App\Domain\Stock\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Support\AlertMode;
use App\Domain\Stock\Support\StockLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Yeşil/Sarı/Kırmızı stok seviyesinin tek hesaplama noktası (proje.md Bölüm 7).
 * Dashboard, raporlar ve bildirimler hep buradan geçer — ayrı bir hesaplama
 * yolu açılmaz.
 *
 * Aşama 29.1'den beri eşik ürün bazlıdır: ürün kartında "miktar bazlı" veya
 * "gün bazlı" bir mod ve ona ait sarı/kırmızı eşikler tanımlanabilir. Eşik
 * girilmemiş üründe eski sabit varsayılan (config/stock.php) uygulanır, yani
 * geriye dönük uyumluluk korunur.
 */
class StockLevelService
{
    /**
     * Bir ürünün güncel stok seviyesini hesaplar. Ürünün tüm depolardaki aktif
     * (miktarı > 0) lotları toplanarak değerlendirilir.
     *
     * @return array{level: StockLevel, quantity: float, reasons: array<int, string>}
     */
    public function assess(Product $product): array
    {
        $lots = StockLot::where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->get();

        $quantity = (float) $lots->sum('quantity');
        $hasExpiredLot = $lots->contains(fn (StockLot $lot) => $lot->isExpired());
        $nearestExpiryDays = $this->nearestExpiryDays($lots);

        $mode = $product->alertMode();
        $criticalThreshold = $this->criticalThreshold($product, $mode);
        $lowThreshold = $this->lowThreshold($product, $mode);

        $reasons = [];

        // Moddan bağımsız mutlak kurallar (proje.md Bölüm 7 tablosu):
        // stok tükendiyse veya stokta SKT'si geçmiş lot varsa ürün kırmızıdır.
        if ($quantity <= 0) {
            $reasons[] = 'Stok tamamen tükendi.';
        }

        if ($hasExpiredLot) {
            $reasons[] = 'Son kullanma tarihi geçmiş, hâlâ stokta duran bir lot var.';
        }

        $isCritical = $quantity <= 0 || $hasExpiredLot;

        if ($mode === AlertMode::Days) {
            // Gün bazlı: ürünün kendi gün eşikleri SKT'ye kalan güne bakar.
            if ($nearestExpiryDays !== null && $nearestExpiryDays <= $criticalThreshold) {
                $reasons[] = "Son kullanma tarihine {$nearestExpiryDays} gün kaldı (kırmızı eşik: ".$this->formatQuantity($criticalThreshold).' gün).';
                $isCritical = true;
            }
        } elseif ($quantity > 0 && $quantity <= $criticalThreshold) {
            $reasons[] = "Kalan miktar kritik seviyede: {$this->formatQuantity($quantity)} {$product->base_unit}.";
            $isCritical = true;
        }

        if ($isCritical) {
            return [
                'level' => StockLevel::Critical,
                'quantity' => $quantity,
                'reasons' => $reasons,
            ];
        }

        $belowMinStock = $product->min_stock > 0 && $quantity <= $product->min_stock;

        if ($mode === AlertMode::Days) {
            // Gün bazlı modda miktar eşiği ürünün kendi min_stock alanıdır;
            // sabit miktar varsayılanı bu ürünler için devreye girmez.
            $expiringSoon = $nearestExpiryDays !== null && $nearestExpiryDays <= $lowThreshold;
            $belowLowThreshold = false;
        } else {
            $expiringSoon = $nearestExpiryDays !== null && $nearestExpiryDays <= (int) config('stock.levels.expiry_warning_days');
            $belowLowThreshold = $quantity <= $lowThreshold;
        }

        if ($belowMinStock || $belowLowThreshold || $expiringSoon) {
            if ($belowMinStock) {
                $reasons[] = "Stok, tanımlı minimum seviyenin ({$product->min_stock} {$product->base_unit}) altına düştü: {$this->formatQuantity($quantity)} {$product->base_unit}.";
            } elseif ($belowLowThreshold) {
                $reasons[] = "Kalan miktar azaldı: {$this->formatQuantity($quantity)} {$product->base_unit}.";
            }

            if ($expiringSoon) {
                $reasons[] = "Son kullanma tarihine {$nearestExpiryDays} gün kaldı.";
            }

            return [
                'level' => StockLevel::Low,
                'quantity' => $quantity,
                'reasons' => $reasons,
            ];
        }

        return [
            'level' => StockLevel::Normal,
            'quantity' => $quantity,
            'reasons' => [],
        ];
    }

    /**
     * Ürünün kendi kırmızı eşiği; girilmemişse moda göre sabit varsayılan.
     */
    private function criticalThreshold(Product $product, AlertMode $mode): float
    {
        return $product->alert_critical_threshold ?? (float) match ($mode) {
            AlertMode::Days => config('stock.levels.expiry_critical_days'),
            AlertMode::Quantity => config('stock.levels.critical_quantity_threshold'),
        };
    }

    /**
     * Ürünün kendi sarı eşiği; girilmemişse moda göre sabit varsayılan.
     */
    private function lowThreshold(Product $product, AlertMode $mode): float
    {
        return $product->alert_low_threshold ?? (float) match ($mode) {
            AlertMode::Days => config('stock.levels.expiry_warning_days'),
            AlertMode::Quantity => config('stock.levels.low_quantity_threshold'),
        };
    }

    /**
     * @param  Collection<int, StockLot>  $lots
     */
    private function nearestExpiryDays(Collection $lots): ?int
    {
        $days = $lots
            ->filter(fn (StockLot $lot) => $lot->expiry_date !== null && ! $lot->isExpired())
            ->map(fn (StockLot $lot) => Carbon::today()->diffInDays($lot->expiry_date->copy()->startOfDay(), false))
            ->min();

        return $days === null ? null : (int) $days;
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }
}
