<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Support\StockMovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Stok hareket raporu (proje.md Bölüm 11: "stok (giriş/çıkış/transfer/sayım/
 * hareket geçmişi)"). Tüm hareketler — iptal edilenler ve iptal kayıtları
 * dahil — listelenir; böylece bir depo için tüm zamanların net toplamı o
 * deponun güncel stoğuna birebir eşittir (append-only defter).
 */
class MovementReportService
{
    /**
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return Builder<StockMovement>
     */
    public function query(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds, ?string $type = null): Builder
    {
        return StockMovement::query()
            ->select('stock_movements.*')
            ->join('stock_lots', 'stock_movements.lot_id', '=', 'stock_lots.id')
            ->join('products', 'stock_lots.product_id', '=', 'products.id')
            ->where('products.organization_id', $organizationId)
            ->inBranches($filters->branchScope($accessibleBranchIds))
            ->when($filters->warehouseId, fn ($query, $value) => $query->where('stock_movements.warehouse_id', $value))
            ->when($filters->categoryId, fn ($query, $value) => $query->where('products.category_id', $value))
            ->when($filters->supplierId, fn ($query, $value) => $query->where('products.supplier_id', $value))
            ->when($filters->from, fn ($query, $value) => $query->where('stock_movements.created_at', '>=', $value))
            ->when($filters->to, fn ($query, $value) => $query->where('stock_movements.created_at', '<=', $value))
            ->when($type, fn ($query, $value) => $query->where('stock_movements.type', $value));
    }

    /**
     * Hareket tipi bazında toplam miktar (ana birim) ve net değişim.
     *
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return array{byType: array<string, float>, net: float, count: int}
     */
    public function totals(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds, ?string $type = null): array
    {
        $rows = $this->query($organizationId, $filters, $accessibleBranchIds, $type)
            ->toBase()
            ->select([])
            ->groupBy('stock_movements.type')
            ->selectRaw('stock_movements.type as type, SUM(stock_movements.quantity) as total, COUNT(*) as movement_count')
            ->get();

        $byType = [];

        foreach (StockMovementType::cases() as $case) {
            $row = $rows->firstWhere('type', $case->value);
            $byType[$case->value] = round((float) ($row->total ?? 0), 2);
        }

        return [
            'byType' => $byType,
            'net' => round((float) $rows->sum('total'), 2),
            'count' => (int) $rows->sum('movement_count'),
        ];
    }

    /**
     * @param  Builder<StockMovement>  $query
     */
    public function withDetails(Builder $query): Builder
    {
        return $query->with(['lot.product.category', 'lot.product.supplier', 'warehouse.branch', 'actor'])
            ->orderByDesc('stock_movements.created_at')
            ->orderByDesc('stock_movements.id');
    }

    /**
     * Dışa aktarım tablosu.
     *
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return array{headings: array<int, string>, rows: Collection<int, array<int, mixed>>, totals: array<int, mixed>}
     */
    public function table(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds, ?string $type = null): array
    {
        $movements = $this->withDetails($this->query($organizationId, $filters, $accessibleBranchIds, $type))->get();
        $totals = $this->totals($organizationId, $filters, $accessibleBranchIds, $type);

        return [
            'headings' => ['Tarih', 'Tip', 'Ürün', 'Kategori', 'Tedarikçi', 'Şube', 'Depo', 'Lot', 'Miktar', 'Birim', 'Personel', 'Açıklama'],
            'rows' => $movements->map(fn (StockMovement $movement) => [
                $movement->created_at->format('d.m.Y H:i'),
                $movement->type->label(),
                $movement->lot->product->name,
                $movement->lot->product->category?->name,
                $movement->lot->product->supplier?->name,
                $movement->warehouse->branch->name,
                $movement->warehouse->name,
                $movement->lot->lot_no,
                (float) $movement->quantity,
                $movement->lot->product->base_unit,
                $movement->actor?->name,
                $movement->reason,
            ]),
            'totals' => ['Net değişim', '', '', '', '', '', '', '', $totals['net'], '', '', "{$totals['count']} hareket"],
        ];
    }
}
