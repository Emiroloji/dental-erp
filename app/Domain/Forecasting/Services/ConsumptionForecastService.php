<?php

namespace App\Domain\Forecasting\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Forecasting\Support\ForecastRisk;
use App\Domain\Forecasting\Support\ForecastTrend;
use App\Domain\Forecasting\Support\HoltForecaster;
use App\Domain\Forecasting\Support\ProductForecast;
use App\Domain\Reporting\Services\MovementReportService;
use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Stock\Support\StockOutReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stok tüketim tahmini (Faz 4 — Aşama 24). İstatistiksel yöntem, dış servis
 * yok (bkz. config/forecasting.php ve HoltForecaster).
 *
 * Kullanımın tanımı raporlarla aynıdır (UsageReportService): yalnızca klinik
 * içi kullanım ve sarf nedenli çıkışlar; iptal edilen hareketler sayılmaz.
 * Hasar, SKT imhası, iade ve transfer tüketim değildir.
 *
 * Kapsam: rapor filtreleri (şube, depo, kategori, tedarikçi) ve görüntüleyenin
 * Raporlar modülündeki şube yetkisi. Stok, yalnızca SKT'si geçmemiş lotlardan
 * sayılır — süresi geçmiş stok kullanılabilir değildir.
 */
class ConsumptionForecastService
{
    public function __construct(private readonly MovementReportService $movements) {}

    /**
     * Kullanımı veya kullanılabilir stoğu olan aktif ürünlerin tahmini,
     * en acil olan başta.
     *
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return Collection<int, ProductForecast>
     */
    public function forecast(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds, ?string $search = null): Collection
    {
        $weeks = max(1, (int) config('forecasting.history_weeks'));
        $today = now()->startOfDay();
        $windowStart = $today->copy()->subDays($weeks * 7 - 1);

        $usageByProduct = $this->dailyUsage($organizationId, $filters, $accessibleBranchIds, $windowStart);

        $products = Product::query()
            ->where('status', 'active')
            ->when($filters->categoryId, fn ($query, $value) => $query->where('category_id', $value))
            ->when($filters->supplierId, fn ($query, $value) => $query->where('supplier_id', $value))
            ->when(filled($search), fn ($query) => $query->where(fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('code', "%{$search}%")))
            ->with('category')
            ->orderBy('name')
            ->get();

        $stock = $this->stock($products->modelKeys(), $filters, $accessibleBranchIds);

        return $products
            ->map(function (Product $product) use ($usageByProduct, $stock, $windowStart, $today, $weeks) {
                $usable = (float) ($stock[$product->id]['usable'] ?? 0);
                $expired = (float) ($stock[$product->id]['expired'] ?? 0);
                $daily = $usageByProduct->get($product->id, collect());

                if ($daily->isEmpty() && $usable <= 0) {
                    return null;
                }

                return $this->forProduct($product, $usable, $expired, $this->weeklySeries($daily, $windowStart, $weeks), $today);
            })
            ->filter()
            ->sortBy([
                fn (ProductForecast $a, ProductForecast $b) => $a->risk->order() <=> $b->risk->order(),
                fn (ProductForecast $a, ProductForecast $b) => ($a->daysOfCover ?? PHP_FLOAT_MAX) <=> ($b->daysOfCover ?? PHP_FLOAT_MAX),
                fn (ProductForecast $a, ProductForecast $b) => strcmp($a->product->name, $b->product->name),
            ])
            ->values();
    }

    /**
     * @param  array<int, float>  $weekly  Eskiden yeniye haftalık kullanım (pencerenin tamamı)
     */
    public function forProduct(Product $product, float $usableStock, float $expiredStock, array $weekly, ?Carbon $today = null): ProductForecast
    {
        $today ??= now()->startOfDay();

        // Seri, pencere içindeki ilk kullanım haftasından başlar; ürün yeni
        // kullanılmaya başlandıysa önceki boş haftalar tahmini aşağı çekmesin.
        $firstUsedWeek = collect($weekly)->search(fn (float $value) => $value > 0);
        $series = $firstUsedWeek === false ? [] : array_slice($weekly, $firstUsedWeek);

        $trend = ForecastTrend::Flat;
        $lowConfidence = false;

        if ($series === []) {
            $dailyRate = 0.0;
        } elseif (count($series) < (int) config('forecasting.min_history_weeks')) {
            // Kısa geçmiş: basit ortalama, "düşük güven".
            $dailyRate = array_sum($series) / (count($series) * 7);
            $lowConfidence = true;
        } else {
            $holt = HoltForecaster::forecast($series, (float) config('forecasting.alpha'), (float) config('forecasting.beta'));
            $dailyRate = $holt['next'] / 7;
            $trend = $this->trend($holt['level'], $holt['trend']);
        }

        $dailyRate = round($dailyRate, 4);
        $daysOfCover = $dailyRate > 0 ? round($usableStock / $dailyRate, 1) : null;

        return new ProductForecast(
            product: $product,
            usableStock: $usableStock,
            expiredStock: $expiredStock,
            dailyRate: $dailyRate,
            horizonUsage: round($dailyRate * (int) config('forecasting.horizon_days'), 2),
            daysOfCover: $daysOfCover,
            stockoutDate: $daysOfCover !== null ? $today->copy()->addDays((int) floor($daysOfCover)) : null,
            minStockDate: $this->minStockDate($product, $usableStock, $dailyRate, $today),
            trend: $trend,
            risk: $this->risk($usableStock, $dailyRate, $daysOfCover),
            lowConfidence: $lowConfidence,
            weeklyUsage: $weekly,
        );
    }

    /**
     * Ürün → (gün → kullanım) — tek gruplanmış sorgu.
     *
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return Collection<int, Collection<string, float>>
     */
    private function dailyUsage(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds, Carbon $windowStart): Collection
    {
        $window = new ReportFilters(
            from: $windowStart,
            to: now()->endOfDay(),
            branchId: $filters->branchId,
            warehouseId: $filters->warehouseId,
            categoryId: $filters->categoryId,
            supplierId: $filters->supplierId,
        );

        return $this->movements->query($organizationId, $window, $accessibleBranchIds)
            ->withoutCancelled()
            ->where('stock_movements.type', StockMovementType::Out->value)
            ->whereIn('stock_movements.reason_code', array_column(StockOutReason::usageReasons(), 'value'))
            ->toBase()
            ->select([])
            ->selectRaw('stock_lots.product_id as product_id, DATE(stock_movements.created_at) as day, SUM(-stock_movements.quantity) as used')
            ->groupByRaw('stock_lots.product_id, DATE(stock_movements.created_at)')
            ->get()
            ->groupBy('product_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn (object $row) => [(string) $row->day => (float) $row->used]));
    }

    /**
     * @param  Collection<string, float>  $daily
     * @return array<int, float>
     */
    private function weeklySeries(Collection $daily, Carbon $windowStart, int $weeks): array
    {
        $series = array_fill(0, $weeks, 0.0);

        foreach ($daily as $day => $used) {
            $index = intdiv((int) $windowStart->diffInDays(Carbon::parse($day)->startOfDay()), 7);

            if ($index >= 0 && $index < $weeks) {
                $series[$index] += $used;
            }
        }

        return array_map(fn (float $value) => round($value, 4), $series);
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return array<int, array{usable: float, expired: float}>
     */
    private function stock(array $productIds, ReportFilters $filters, ?array $accessibleBranchIds): array
    {
        $stock = [];

        StockLot::query()
            ->whereIn('product_id', $productIds)
            ->inBranches($filters->branchScope($accessibleBranchIds))
            ->when($filters->warehouseId, fn ($query, $value) => $query->where('warehouse_id', $value))
            ->where('quantity', '>', 0)
            ->get(['product_id', 'quantity', 'expiry_date'])
            ->each(function (StockLot $lot) use (&$stock) {
                $key = $lot->isExpired() ? 'expired' : 'usable';
                $stock[$lot->product_id][$key] = ($stock[$lot->product_id][$key] ?? 0) + (float) $lot->quantity;
            });

        return $stock;
    }

    private function trend(float $level, float $trend): ForecastTrend
    {
        if ($level <= 0) {
            return $trend > 0 ? ForecastTrend::Up : ForecastTrend::Flat;
        }

        $ratio = $trend / $level;
        $threshold = (float) config('forecasting.trend_threshold');

        return match (true) {
            $ratio > $threshold => ForecastTrend::Up,
            $ratio < -$threshold => ForecastTrend::Down,
            default => ForecastTrend::Flat,
        };
    }

    private function risk(float $usableStock, float $dailyRate, ?float $daysOfCover): ForecastRisk
    {
        return match (true) {
            $usableStock <= 0 => ForecastRisk::OutOfStock,
            $dailyRate <= 0 => ForecastRisk::NoUsage,
            $daysOfCover <= (int) config('forecasting.critical_days') => ForecastRisk::Critical,
            $daysOfCover <= (int) config('forecasting.warning_days') => ForecastRisk::Soon,
            default => ForecastRisk::Sufficient,
        };
    }

    /**
     * Ürün kartındaki minimum seviyeye inilecek tahmini tarih — sipariş zamanı
     * için. Zaten altındaysa bugün.
     */
    private function minStockDate(Product $product, float $usableStock, float $dailyRate, Carbon $today): ?Carbon
    {
        $min = (float) $product->min_stock;

        if ($min <= 0 || $dailyRate <= 0) {
            return null;
        }

        if ($usableStock <= $min) {
            return $today->copy();
        }

        return $today->copy()->addDays((int) floor(($usableStock - $min) / $dailyRate));
    }
}
