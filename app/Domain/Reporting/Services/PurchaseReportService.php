<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Returns\Support\ReturnStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satın alma ve iade raporu (proje.md Bölüm 5 ve 11: "tedarikçi bazlı satın
 * alma geçmişi, toplam harcama"; Bölüm 10 iade/kredi notu). Tedarikçi başına:
 * - Sipariş: dönemde açılmış ve Admin onayından geçmiş siparişler, tutarı.
 * - Teslim: dönemde teslim alınan miktar ve alış fiyatıyla tutarı (harcama).
 * - Açık sipariş: dönemde açılmış, teslimatı bekleyen kalan tutar.
 * - İade: dönemde açılan (reddedilmemiş) iadeler, kargolanan miktar ve kapanışta alınan kredi notu.
 *
 * Depo/şube filtresi sipariş, teslim ve iadenin deposuna; kategori filtresi
 * kalemin ürününe uygulanır.
 */
class PurchaseReportService
{
    private const METRICS = ['order_count', 'ordered_amount', 'open_amount', 'received_quantity', 'received_amount', 'return_count', 'returned_quantity', 'credit_amount'];

    /**
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return Collection<int, array<string, float|int>> tedarikçi id => metrikler
     */
    public function metrics(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds): Collection
    {
        $branchIds = $filters->branchScope($accessibleBranchIds);
        $ordered = [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Completed];
        $open = PurchaseOrderStatus::openStatuses();

        $orders = $this->scoped(DB::table('purchase_order_lines')
            ->join('purchase_orders', 'purchase_order_lines.purchase_order_id', '=', 'purchase_orders.id')
            ->join('products', 'purchase_order_lines.product_id', '=', 'products.id')
            ->where('purchase_orders.organization_id', $organizationId)
            ->whereIn('purchase_orders.status', array_map(fn ($status) => $status->value, $ordered)), 'purchase_orders', $filters, $branchIds)
            ->groupBy('purchase_orders.supplier_id')
            ->selectRaw('purchase_orders.supplier_id as supplier_id, COUNT(DISTINCT purchase_orders.id) as order_count')
            ->selectRaw('SUM(purchase_order_lines.quantity * purchase_order_lines.unit_price) as ordered_amount')
            ->selectRaw('SUM(CASE WHEN purchase_orders.status IN ('.$this->quoted($open).') THEN (purchase_order_lines.quantity - purchase_order_lines.received_quantity) * purchase_order_lines.unit_price ELSE 0 END) as open_amount')
            ->get();

        $receipts = $this->scoped(DB::table('purchase_receipt_lines')
            ->join('purchase_receipts', 'purchase_receipt_lines.purchase_receipt_id', '=', 'purchase_receipts.id')
            ->join('purchase_orders', 'purchase_receipts.purchase_order_id', '=', 'purchase_orders.id')
            ->join('purchase_order_lines', 'purchase_receipt_lines.purchase_order_line_id', '=', 'purchase_order_lines.id')
            ->join('products', 'purchase_order_lines.product_id', '=', 'products.id')
            ->where('purchase_orders.organization_id', $organizationId), 'purchase_receipts', $filters, $branchIds, supplierColumn: 'purchase_orders.supplier_id')
            ->groupBy('purchase_orders.supplier_id')
            ->selectRaw('purchase_orders.supplier_id as supplier_id')
            ->selectRaw('SUM(purchase_receipt_lines.quantity) as received_quantity')
            ->selectRaw('SUM(purchase_receipt_lines.quantity * purchase_receipt_lines.unit_cost) as received_amount')
            ->get();

        $shipped = [ReturnStatus::Shipped, ReturnStatus::SupplierApproved, ReturnStatus::Completed];

        $returns = $this->scoped(DB::table('returns')
            ->join('products', 'returns.product_id', '=', 'products.id')
            ->where('returns.organization_id', $organizationId)
            ->where('returns.status', '!=', ReturnStatus::Rejected->value), 'returns', $filters, $branchIds)
            ->groupBy('returns.supplier_id')
            ->selectRaw('returns.supplier_id as supplier_id, COUNT(*) as return_count')
            ->selectRaw('SUM(CASE WHEN returns.status IN ('.$this->quoted($shipped).') THEN returns.quantity ELSE 0 END) as returned_quantity')
            ->selectRaw("SUM(CASE WHEN returns.status = '".ReturnStatus::Completed->value."' THEN COALESCE(returns.credit_amount, 0) ELSE 0 END) as credit_amount")
            ->get();

        $bySupplier = collect();

        foreach ([$orders, $receipts, $returns] as $rows) {
            foreach ($rows as $row) {
                $current = $bySupplier->get((int) $row->supplier_id, $this->emptyMetrics());

                foreach (self::METRICS as $metric) {
                    if (property_exists($row, $metric)) {
                        $current[$metric] = str_ends_with($metric, '_count') ? (int) $row->{$metric} : round((float) $row->{$metric}, 2);
                    }
                }

                $bySupplier->put((int) $row->supplier_id, $current);
            }
        }

        return $bySupplier;
    }

    /**
     * Etkinliği olan tedarikçiler, sayfalı (mimari.md: hiçbir liste tümünü tek seferde çekmez).
     *
     * @param  Collection<int, array<string, float|int>>  $metrics
     */
    public function suppliers(Collection $metrics, int $perPage = 15): LengthAwarePaginator
    {
        $page = Supplier::whereIn('id', $metrics->keys())->orderBy('name')->paginate($perPage);

        $page->setCollection($page->getCollection()->map(fn (Supplier $supplier) => ['supplier' => $supplier, ...$metrics->get($supplier->id)]));

        return $page;
    }

    /**
     * @param  Collection<int, array<string, float|int>>  $metrics
     * @return array<string, float|int>
     */
    public function totals(Collection $metrics): array
    {
        return collect(self::METRICS)->mapWithKeys(fn (string $metric) => [
            $metric => str_ends_with($metric, '_count') ? (int) $metrics->sum($metric) : round((float) $metrics->sum($metric), 2),
        ])->all();
    }

    /**
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return array{headings: array<int, string>, rows: Collection<int, array<int, mixed>>, totals: array<int, mixed>}
     */
    public function table(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds): array
    {
        $metrics = $this->metrics($organizationId, $filters, $accessibleBranchIds);
        $names = Supplier::whereIn('id', $metrics->keys())->pluck('name', 'id');
        $totals = $this->totals($metrics);

        $row = fn (string $name, array $values) => [
            $name, $values['order_count'], $values['ordered_amount'], $values['received_quantity'], $values['received_amount'],
            $values['open_amount'], $values['return_count'], $values['returned_quantity'], $values['credit_amount'],
        ];

        return [
            'headings' => ['Tedarikçi', 'Sipariş', 'Sipariş Tutarı (₺)', 'Teslim Alınan', 'Teslim Tutarı (₺)', 'Açık Sipariş (₺)', 'İade', 'İade Edilen', 'Kredi Notu (₺)'],
            'rows' => $metrics->sortBy(fn ($values, $id) => $names[$id] ?? '')->map(fn ($values, $id) => $row($names[$id] ?? '—', $values))->values(),
            'totals' => $row('Toplam', $totals),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function emptyMetrics(): array
    {
        return collect(self::METRICS)->mapWithKeys(fn (string $metric) => [$metric => str_ends_with($metric, '_count') ? 0 : 0.0])->all();
    }

    /**
     * Ortak filtreler: tarih (kaydın created_at'i), şube/depo (kaydın deposu), kategori, tedarikçi.
     *
     * @param  array<int, int>|null  $branchIds
     */
    private function scoped(Builder $query, string $table, ReportFilters $filters, ?array $branchIds, ?string $supplierColumn = null): Builder
    {
        $supplierColumn ??= "{$table}.supplier_id";

        return $query
            ->when($branchIds !== null, fn ($query) => $query->whereIn("{$table}.warehouse_id", Warehouse::query()->select('id')->whereIn('branch_id', $branchIds)))
            ->when($filters->warehouseId, fn ($query, $value) => $query->where("{$table}.warehouse_id", $value))
            ->when($filters->supplierId, fn ($query, $value) => $query->where($supplierColumn, $value))
            ->when($filters->categoryId, fn ($query, $value) => $query->where('products.category_id', $value))
            ->when($filters->from, fn ($query, $value) => $query->where("{$table}.created_at", '>=', $value))
            ->when($filters->to, fn ($query, $value) => $query->where("{$table}.created_at", '<=', $value));
    }

    /**
     * @param  array<int, \BackedEnum>  $statuses
     */
    private function quoted(array $statuses): string
    {
        return implode(', ', array_map(fn (\BackedEnum $status) => "'{$status->value}'", $statuses));
    }
}
