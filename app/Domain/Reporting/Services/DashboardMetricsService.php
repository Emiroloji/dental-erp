<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockLevelService;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * proje.md Bölüm 11'deki dashboard kartlarının MVP 1'de (Organization/Access/
 * Catalog/Stock domain'leriyle) kurulabilecek kısmını hesaplar. "Bekleyen
 * talepler" ve "satın alma" gibi kartlar Transfer/Purchasing domain'leri henüz
 * kurulmadığı için (Aşama 11/12) kasıtlı olarak dışarıda bırakıldı.
 *
 * mimari.md Bölüm 6: sonuç kısa süreli cache'lenir; stok hareketi olduğunda
 * StockMovementService bu servisin forget() metoduyla cache'i geçersiz kılar.
 */
class DashboardMetricsService
{
    public function __construct(private readonly StockLevelService $levels) {}

    /**
     * @return array<string, mixed>
     */
    public function summaryFor(int $organizationId): array
    {
        $ttl = (int) config('reporting.dashboard_cache_ttl', 120);

        return Cache::remember(
            $this->cacheKey($organizationId),
            $ttl,
            fn () => $this->compute($organizationId),
        );
    }

    public function forget(int $organizationId): void
    {
        Cache::forget($this->cacheKey($organizationId));
    }

    private function cacheKey(int $organizationId): string
    {
        // Özetin yapısı değiştiğinde sürüm artırılır; eski yapıdaki cache girdisi
        // okunmaz (yeni alanlar eksik olduğu için dashboard hata verirdi).
        return "dashboard:summary:v2:organization:{$organizationId}";
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(int $organizationId): array
    {
        $products = Product::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->get();

        $levelCounts = ['normal' => 0, 'low' => 0, 'critical' => 0];

        foreach ($products as $product) {
            $level = $this->levels->assess($product)['level'];
            $levelCounts[$level->value]++;
        }

        $lots = StockLot::whereHas('product', fn ($query) => $query->where('organization_id', $organizationId))
            ->where('quantity', '>', 0)
            ->get();

        $expiryWarningDays = (int) config('stock.levels.expiry_warning_days');

        $expiredCount = $lots->filter(fn (StockLot $lot) => $lot->isExpired())->count();

        $expiringSoonCount = $lots->filter(function (StockLot $lot) use ($expiryWarningDays) {
            if ($lot->expiry_date === null || $lot->isExpired()) {
                return false;
            }

            return Carbon::today()->diffInDays($lot->expiry_date->copy()->startOfDay(), false) <= $expiryWarningDays;
        })->count();

        $movements = StockMovement::whereHas('lot.product', fn ($query) => $query->where('organization_id', $organizationId))
            ->withoutCancelled();

        $todayIn = (float) (clone $movements)->where('type', 'in')->whereDate('created_at', Carbon::today())->sum('quantity');
        $todayOut = abs((float) (clone $movements)->where('type', 'out')->whereDate('created_at', Carbon::today())->sum('quantity'));

        // Kullanım = yalnızca gerçek klinik tüketimi (StockOutReason::usageReasons()).
        // Diğer çıkışlar (hasar, SKT imhası, iade, transfer, nedeni kodlanmamış)
        // stoğu azaltır ama kullanıma karışmaz; ayrı olarak raporlanır.
        $usageCodes = array_map(fn (StockOutReason $reason) => $reason->value, StockOutReason::usageReasons());

        $monthlyOut = (clone $movements)->where('type', 'out')
            ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]);

        $monthlyUsage = abs((float) (clone $monthlyOut)->whereIn('reason_code', $usageCodes)->sum('quantity'));

        $monthlyOtherOutByReason = (clone $monthlyOut)
            ->where(fn ($query) => $query->whereNull('reason_code')->orWhereNotIn('reason_code', $usageCodes))
            ->toBase()
            ->groupBy('reason_code')
            ->orderByDesc('total_quantity')
            ->selectRaw('reason_code, SUM(ABS(quantity)) as total_quantity')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (StockOutReason::tryFrom((string) $row->reason_code)?->label() ?? 'Nedeni belirtilmemiş') => (float) $row->total_quantity,
            ])
            ->all();

        $topUsedProducts = StockMovement::query()
            ->join('stock_lots', 'stock_movements.lot_id', '=', 'stock_lots.id')
            ->join('products', 'stock_lots.product_id', '=', 'products.id')
            ->where('products.organization_id', $organizationId)
            ->where('stock_movements.type', 'out')
            ->whereIn('stock_movements.reason_code', $usageCodes)
            ->withoutCancelled()
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('used_quantity')
            ->limit(5)
            ->selectRaw('products.id, products.name, SUM(ABS(stock_movements.quantity)) as used_quantity')
            ->get();

        $branchDistribution = StockLot::query()
            ->join('warehouses', 'stock_lots.warehouse_id', '=', 'warehouses.id')
            ->join('branches', 'warehouses.branch_id', '=', 'branches.id')
            ->join('products', 'stock_lots.product_id', '=', 'products.id')
            ->where('products.organization_id', $organizationId)
            ->where('stock_lots.quantity', '>', 0)
            ->groupBy('branches.id', 'branches.name')
            ->orderByDesc('total_quantity')
            ->selectRaw('branches.id, branches.name, SUM(stock_lots.quantity) as total_quantity')
            ->get();

        return [
            'productCount' => $products->count(),
            'categoryCount' => Category::where('organization_id', $organizationId)->where('status', 'active')->count(),
            'supplierCount' => Supplier::where('organization_id', $organizationId)->where('status', 'active')->count(),
            'staffCount' => User::where('organization_id', $organizationId)->where('role', User::ROLE_STAFF)->count(),
            'branchCount' => Branch::where('organization_id', $organizationId)->where('status', 'active')->count(),
            'totalStockQuantity' => (float) $lots->sum('quantity'),
            'totalStockValue' => (float) $lots->sum(fn (StockLot $lot) => $lot->quantity * $lot->unit_cost),
            'levelCounts' => $levelCounts,
            'expiredLotCount' => $expiredCount,
            'expiringSoonLotCount' => $expiringSoonCount,
            'todayIn' => $todayIn,
            'todayOut' => $todayOut,
            'monthlyUsage' => $monthlyUsage,
            'monthlyOtherOut' => (float) array_sum($monthlyOtherOutByReason),
            'monthlyOtherOutByReason' => $monthlyOtherOutByReason,
            'topUsedProducts' => $topUsedProducts->map(fn ($row) => ['name' => $row->name, 'used' => (float) $row->used_quantity])->all(),
            'branchDistribution' => $branchDistribution->map(fn ($row) => ['name' => $row->name, 'quantity' => (float) $row->total_quantity])->all(),
            'computedAt' => now()->toDateTimeString(),
        ];
    }
}
