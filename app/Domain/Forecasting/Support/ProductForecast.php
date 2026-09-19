<?php

namespace App\Domain\Forecasting\Support;

use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Carbon;

/**
 * Bir ürünün tüketim tahmini (ana birim üzerinden).
 */
final class ProductForecast
{
    /**
     * @param  array<int, float>  $weeklyUsage  Son haftaların kullanımı, eskiden yeniye
     */
    public function __construct(
        public readonly Product $product,
        public readonly float $usableStock,
        public readonly float $expiredStock,
        public readonly float $dailyRate,
        public readonly float $horizonUsage,
        public readonly ?float $daysOfCover,
        public readonly ?Carbon $stockoutDate,
        public readonly ?Carbon $minStockDate,
        public readonly ForecastTrend $trend,
        public readonly ForecastRisk $risk,
        public readonly bool $lowConfidence,
        public readonly array $weeklyUsage,
    ) {}
}
