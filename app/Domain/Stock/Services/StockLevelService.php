<?php

namespace App\Domain\Stock\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Support\StockLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Yeşil/Sarı/Kırmızı stok seviyesinin tek hesaplama noktası (proje.md Bölüm 7).
 * Dashboard, raporlar ve bildirimler hep buradan geçer — ayrı bir hesaplama
 * yolu açılmaz.
 *
 * Aşama 29.1'den beri eşik ürün bazlıdır ve iki bağımsız eksende ölçülür:
 * kalan miktar (adet) ve son kullanma tarihine kalan süre (gün). Ürün kartında
 * seçilen mod bu eksenlerden hangilerinin ürünün kendi eşikleriyle
 * değerlendirileceğini belirler; "İkisi birden" modunda hangi eksen önce eşiğe
 * ulaşırsa ürün o seviyeye geçer. Mod seçilmemiş üründe eski sabit varsayılan
 * (config/stock.php) uygulanır, yani geriye dönük uyumluluk korunur.
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
        $expiryDays = $this->nearestExpiryDays($lots);

        $mode = $product->alert_mode;

        // Miktar ekseni: ürün yalnızca SKT'ye göre izleniyorsa kapanır; kapalıyken
        // bile ürünün kendi min_stock alanı sarı uyarı vermeye devam eder.
        $tracksQuantity = $mode === null || $mode->tracksQuantity();
        $quantityLow = $product->alert_quantity_low ?? (float) config('stock.levels.low_quantity_threshold');
        $quantityCritical = $product->alert_quantity_critical ?? (float) config('stock.levels.critical_quantity_threshold');

        // SKT ekseni: ürün kendi gün eşiğini tanımladıysa onun değerleri, aksi
        // hâlde yalnızca sabit sarı uyarı penceresi (kırmızı gün eşiği olmaz).
        $tracksExpiry = $mode !== null && $mode->tracksExpiry();
        $expiryLowDays = $tracksExpiry ? $product->alert_expiry_low_days : (int) config('stock.levels.expiry_warning_days');
        $expiryCriticalDays = $tracksExpiry ? $product->alert_expiry_critical_days : null;

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

        if ($tracksQuantity && $quantity > 0 && $quantity <= $quantityCritical) {
            $reasons[] = "Kalan miktar kritik seviyede: {$this->format($quantity)} {$product->base_unit}.";
            $isCritical = true;
        }

        if ($expiryCriticalDays !== null && $expiryDays !== null && $expiryDays <= $expiryCriticalDays) {
            $reasons[] = "Son kullanma tarihine {$expiryDays} gün kaldı (kırmızı eşik: {$expiryCriticalDays} gün).";
            $isCritical = true;
        }

        if ($isCritical) {
            return ['level' => StockLevel::Critical, 'quantity' => $quantity, 'reasons' => $reasons];
        }

        $belowMinStock = $product->min_stock > 0 && $quantity <= $product->min_stock;
        $belowQuantityLow = $tracksQuantity && $quantity <= $quantityLow;
        $expiringSoon = $expiryLowDays !== null && $expiryDays !== null && $expiryDays <= $expiryLowDays;

        if ($belowMinStock || $belowQuantityLow || $expiringSoon) {
            if ($belowMinStock) {
                $reasons[] = "Stok, tanımlı minimum seviyenin ({$product->min_stock} {$product->base_unit}) altına düştü: {$this->format($quantity)} {$product->base_unit}.";
            } elseif ($belowQuantityLow) {
                $reasons[] = "Kalan miktar azaldı: {$this->format($quantity)} {$product->base_unit}.";
            }

            if ($expiringSoon) {
                $reasons[] = "Son kullanma tarihine {$expiryDays} gün kaldı.";
            }

            return ['level' => StockLevel::Low, 'quantity' => $quantity, 'reasons' => $reasons];
        }

        return ['level' => StockLevel::Normal, 'quantity' => $quantity, 'reasons' => []];
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

    private function format(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }
}
