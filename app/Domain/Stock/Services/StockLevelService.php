<?php

namespace App\Domain\Stock\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Support\StockLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockLevelService
{
    /**
     * Bir ürünün güncel stok seviyesini (proje.md Bölüm 7'deki Yeşil/Sarı/Kırmızı
     * tablosuna göre) hesaplar. Ürünün tüm depolardaki aktif (miktarı > 0) lotları
     * toplanarak değerlendirilir.
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

        $criticalThreshold = (float) config('stock.levels.critical_quantity_threshold');
        $lowThreshold = (float) config('stock.levels.low_quantity_threshold');
        $expiryWarningDays = (int) config('stock.levels.expiry_warning_days');

        $reasons = [];

        if ($quantity <= 0) {
            $reasons[] = 'Stok tamamen tükendi.';
        } elseif ($quantity <= $criticalThreshold) {
            $reasons[] = "Kalan miktar kritik seviyede: {$this->formatQuantity($quantity)} adet.";
        }

        if ($hasExpiredLot) {
            $reasons[] = 'Son kullanma tarihi geçmiş, hâlâ stokta duran bir lot var.';
        }

        if ($quantity <= 0 || $quantity <= $criticalThreshold || $hasExpiredLot) {
            return [
                'level' => StockLevel::Critical,
                'quantity' => $quantity,
                'reasons' => $reasons,
            ];
        }

        $belowMinStock = $product->min_stock > 0 && $quantity <= $product->min_stock;
        $belowLowThreshold = $quantity <= $lowThreshold;
        $expiringSoon = $nearestExpiryDays !== null && $nearestExpiryDays <= $expiryWarningDays;

        if ($belowMinStock || $belowLowThreshold || $expiringSoon) {
            if ($belowMinStock) {
                $reasons[] = "Stok, tanımlı minimum seviyenin ({$product->min_stock} adet) altına düştü: {$this->formatQuantity($quantity)} adet.";
            } elseif ($belowLowThreshold) {
                $reasons[] = "Kalan miktar azaldı: {$this->formatQuantity($quantity)} adet.";
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
