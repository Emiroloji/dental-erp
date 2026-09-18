<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * fazlar-adimlar.md Aşama 10: depo bazlı stok raporu. proje.md Bölüm 6: stok
 * fiilen depo seviyesinde tutulur, şube görünümü o şubenin depolarının
 * toplamıdır — rapor ikisini yan yana gösterir.
 */
class WarehouseStockReportService
{
    /**
     * Erişilebilir aktif şubelerin özet satırları.
     *
     * @param  array<int, int>|null  $branchIds  null = kısıt yok
     * @return Collection<int, array{id: int, name: string, quantity: float, value: float, warehouse_count: int}>
     */
    public function branchSummaries(?array $branchIds): Collection
    {
        $branches = Branch::where('status', 'active')
            ->when($branchIds !== null, fn ($query) => $query->whereIn('id', $branchIds))
            ->withCount(['warehouses' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')
            ->get();

        $stock = StockLot::query()
            ->join('warehouses', 'stock_lots.warehouse_id', '=', 'warehouses.id')
            ->whereIn('warehouses.branch_id', $branches->modelKeys())
            ->where('stock_lots.quantity', '>', 0)
            ->groupBy('warehouses.branch_id')
            ->selectRaw('warehouses.branch_id, SUM(stock_lots.quantity) as quantity, SUM(stock_lots.quantity * stock_lots.unit_cost) as value')
            ->toBase()
            ->get()
            ->keyBy('branch_id');

        return $branches->map(fn (Branch $branch) => [
            'id' => $branch->id,
            'name' => $branch->name,
            'quantity' => (float) ($stock[$branch->id]->quantity ?? 0),
            'value' => (float) ($stock[$branch->id]->value ?? 0),
            'warehouse_count' => $branch->warehouses_count,
        ])->values();
    }

    /**
     * Bir şubenin ürün × depo stok tablosu. Satırlar (ürünler) sayfalanır;
     * depo ve şube toplamları aramadan bağımsız olarak şubenin tamamını yansıtır.
     *
     * @return array{
     *     warehouses: Collection<int, Warehouse>,
     *     rows: array<int, array{product: Product, quantities: array<int, float>, total: float, value: float}>,
     *     warehouseTotals: array<int, float>,
     *     branchTotal: float,
     *     branchValue: float,
     *     paginator: LengthAwarePaginator,
     * }
     */
    public function forBranch(Branch $branch, ?string $search = null, int $perPage = 20): array
    {
        $warehouses = Warehouse::where('branch_id', $branch->id)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $lotsInBranch = fn () => StockLot::inBranches([$branch->id])->where('quantity', '>', 0);

        $paginator = Product::query()
            ->whereIn('id', $lotsInBranch()->select('product_id'))
            ->when($search, fn ($query, $value) => $query->where(fn ($query) => $query
                ->whereLike('name', "%{$value}%")
                ->orWhereLike('code', "%{$value}%")))
            ->orderBy('name')
            ->paginate($perPage);

        $cells = $lotsInBranch()
            ->whereIn('product_id', $paginator->getCollection()->modelKeys())
            ->groupBy('product_id', 'warehouse_id')
            ->selectRaw('product_id, warehouse_id, SUM(quantity) as quantity, SUM(quantity * unit_cost) as value')
            ->toBase()
            ->get()
            ->groupBy('product_id');

        $emptyRow = array_fill_keys($warehouses->modelKeys(), 0.0);

        $rows = $paginator->getCollection()->map(function (Product $product) use ($cells, $emptyRow) {
            $quantities = $emptyRow;
            $value = 0.0;

            foreach ($cells[$product->id] ?? [] as $cell) {
                $quantities[(int) $cell->warehouse_id] = (float) $cell->quantity;
                $value += (float) $cell->value;
            }

            return [
                'product' => $product,
                'quantities' => $quantities,
                'total' => (float) array_sum($quantities),
                'value' => $value,
            ];
        })->all();

        $totals = $lotsInBranch()
            ->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, SUM(quantity) as quantity, SUM(quantity * unit_cost) as value')
            ->toBase()
            ->get();

        $warehouseTotals = $emptyRow;
        foreach ($totals as $total) {
            $warehouseTotals[(int) $total->warehouse_id] = (float) $total->quantity;
        }

        return [
            'warehouses' => $warehouses,
            'rows' => $rows,
            'warehouseTotals' => $warehouseTotals,
            'branchTotal' => (float) array_sum($warehouseTotals),
            'branchValue' => (float) $totals->sum('value'),
            'paginator' => $paginator,
        ];
    }
}
