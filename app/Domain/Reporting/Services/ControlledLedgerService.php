<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Stock\Models\StockMovement;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Kontrollü Ürün Defteri (Aşama 26): kontrollü işaretli her ürün için dönem
 * açılış bakiyesi, dönem içindeki TÜM hareketler (iptaller dahil — defter
 * silinmez) ve her hareket sonrası bakiye. Kapsam ve filtreler hareket
 * raporuyla aynıdır; bakiye, seçilen şube/depo kapsamındaki stoktur.
 */
class ControlledLedgerService
{
    public function __construct(private readonly MovementReportService $movements) {}

    /**
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return LengthAwarePaginator<int, array{product: Product, opening: float, entries: Collection<int, array{movement: StockMovement, balance: float}>, closing: float}>
     */
    public function ledger(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds, ?int $productId = null, int $perPage = 10): LengthAwarePaginator
    {
        $products = $this->products($filters, $productId)->paginate($perPage);

        $products->setCollection($products->getCollection()->map(fn (Product $product) => $this->forProduct($organizationId, $product, $filters, $accessibleBranchIds)));

        return $products;
    }

    /**
     * Dışa aktarım tablosu (tüm kontrollü ürünler, sayfalama yok).
     *
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return array{headings: array<int, string>, rows: Collection<int, array<int, mixed>>, totals: array<int, mixed>}
     */
    public function table(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds, ?int $productId = null): array
    {
        $rows = collect();

        foreach ($this->products($filters, $productId)->get() as $product) {
            $ledger = $this->forProduct($organizationId, $product, $filters, $accessibleBranchIds);

            $rows->push([$product->name, $filters->from?->format('d.m.Y'), 'Açılış bakiyesi', '', '', '', $ledger['opening'], '', '']);

            foreach ($ledger['entries'] as $entry) {
                $movement = $entry['movement'];
                $rows->push([
                    $product->name,
                    $movement->created_at->format('d.m.Y H:i'),
                    $movement->type->label(),
                    $movement->lot->lot_no,
                    "{$movement->warehouse->branch->name} · {$movement->warehouse->name}",
                    (float) $movement->quantity,
                    $entry['balance'],
                    $movement->actor?->name,
                    $movement->reason,
                ]);
            }

            $rows->push([$product->name, $filters->to?->format('d.m.Y'), 'Kapanış bakiyesi', '', '', '', $ledger['closing'], '', '']);
        }

        return [
            'headings' => ['Ürün', 'Tarih', 'Hareket', 'Lot', 'Şube · Depo', 'Miktar', 'Bakiye', 'Personel', 'Açıklama'],
            'rows' => $rows,
            'totals' => [],
        ];
    }

    private function products(ReportFilters $filters, ?int $productId)
    {
        return Product::query()
            ->where('is_controlled', true)
            ->when($productId, fn ($query, $value) => $query->whereKey($value))
            ->when($filters->categoryId, fn ($query, $value) => $query->where('category_id', $value))
            ->when($filters->supplierId, fn ($query, $value) => $query->where('supplier_id', $value))
            ->orderBy('name');
    }

    /**
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return array{product: Product, opening: float, entries: Collection<int, array{movement: StockMovement, balance: float}>, closing: float}
     */
    private function forProduct(int $organizationId, Product $product, ReportFilters $filters, ?array $accessibleBranchIds): array
    {
        $opening = 0.0;

        if ($filters->from) {
            $before = new ReportFilters(to: $filters->from->copy()->subSecond(), branchId: $filters->branchId, warehouseId: $filters->warehouseId);
            $opening = (float) $this->movements->query($organizationId, $before, $accessibleBranchIds)
                ->where('stock_lots.product_id', $product->id)
                ->sum('stock_movements.quantity');
        }

        $balance = $opening;

        $entries = $this->movements->query($organizationId, $filters, $accessibleBranchIds)
            ->where('stock_lots.product_id', $product->id)
            ->with(['lot', 'warehouse.branch', 'actor'])
            ->orderBy('stock_movements.created_at')
            ->orderBy('stock_movements.id')
            ->get()
            ->map(function (StockMovement $movement) use (&$balance) {
                $balance = round($balance + (float) $movement->quantity, 4);

                return ['movement' => $movement, 'balance' => $balance];
            });

        return ['product' => $product, 'opening' => round($opening, 4), 'entries' => $entries, 'closing' => $balance];
    }
}
