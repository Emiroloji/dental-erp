<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Stock\Support\StockOutReason;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Kullanım ve maliyet raporu (proje.md Bölüm 7 ve 11): ürün bazında seçilen
 * dönemdeki giriş, kullanım (yalnızca klinik tüketim), diğer çıkışlar (hasar,
 * SKT imhası, tedarikçiye iade...), transfer ve sayım farkı; kullanımın
 * maliyeti lotun alış fiyatından hesaplanır.
 *
 * Kurallar dashboard ile aynıdır (DashboardMetricsService): iptal edilen
 * hareketler ve iptal kayıtları sayılmaz; kullanım StockOutReason::usageReasons().
 */
class UsageReportService
{
    public function __construct(private readonly MovementReportService $movements) {}

    /**
     * Ürün başına bir satır.
     *
     * @param  array<int, int>|null  $accessibleBranchIds
     */
    public function query(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds): Builder
    {
        return $this->base($organizationId, $filters, $accessibleBranchIds)
            ->groupBy('products.id', 'products.name', 'products.base_unit')
            ->selectRaw('products.id as product_id, products.name as product_name, products.base_unit as base_unit')
            ->selectRaw($this->aggregates())
            ->orderByDesc('usage_quantity')
            ->orderBy('products.name');
    }

    /**
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return array{in_quantity: float, usage_quantity: float, usage_cost: float, other_out_quantity: float, transfer_net: float, count_adjust: float}
     */
    public function totals(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds): array
    {
        $row = $this->base($organizationId, $filters, $accessibleBranchIds)->selectRaw($this->aggregates())->first();

        return $this->normalize($row);
    }

    /**
     * @return array{in_quantity: float, usage_quantity: float, usage_cost: float, other_out_quantity: float, transfer_net: float, count_adjust: float}
     */
    public function normalize(?object $row): array
    {
        return collect(['in_quantity', 'usage_quantity', 'usage_cost', 'other_out_quantity', 'transfer_net', 'count_adjust'])
            ->mapWithKeys(fn (string $key) => [$key => round((float) ($row->{$key} ?? 0), 2)])
            ->all();
    }

    /**
     * @param  array<int, int>|null  $accessibleBranchIds
     * @return array{headings: array<int, string>, rows: Collection<int, array<int, mixed>>, totals: array<int, mixed>}
     */
    public function table(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds): array
    {
        $totals = $this->totals($organizationId, $filters, $accessibleBranchIds);

        return [
            'headings' => ['Ürün', 'Birim', 'Giriş', 'Kullanım', 'Kullanım Maliyeti (₺)', 'Diğer Çıkışlar', 'Transfer (net)', 'Sayım Farkı'],
            'rows' => $this->query($organizationId, $filters, $accessibleBranchIds)->get()->map(function (object $row) {
                $values = $this->normalize($row);

                return [$row->product_name, $row->base_unit, $values['in_quantity'], $values['usage_quantity'], $values['usage_cost'], $values['other_out_quantity'], $values['transfer_net'], $values['count_adjust']];
            }),
            'totals' => ['Toplam', '', $totals['in_quantity'], $totals['usage_quantity'], $totals['usage_cost'], $totals['other_out_quantity'], $totals['transfer_net'], $totals['count_adjust']],
        ];
    }

    /**
     * @param  array<int, int>|null  $accessibleBranchIds
     */
    private function base(int $organizationId, ReportFilters $filters, ?array $accessibleBranchIds): Builder
    {
        return $this->movements->query($organizationId, $filters, $accessibleBranchIds)
            ->withoutCancelled()
            ->where('stock_movements.type', '!=', StockMovementType::Cancel->value)
            ->toBase()
            ->select([]);
    }

    private function aggregates(): string
    {
        $usage = implode(', ', array_map(fn (StockOutReason $reason) => "'{$reason->value}'", StockOutReason::usageReasons()));
        $in = StockMovementType::In->value;
        $out = StockMovementType::Out->value;
        $return = StockMovementType::ReturnMovement->value;
        $transferIn = StockMovementType::TransferIn->value;
        $transferOut = StockMovementType::TransferOut->value;
        $countAdjust = StockMovementType::CountAdjust->value;
        $isUsage = "stock_movements.type = '{$out}' AND stock_movements.reason_code IN ({$usage})";

        return implode(', ', [
            "SUM(CASE WHEN stock_movements.type = '{$in}' THEN stock_movements.quantity ELSE 0 END) as in_quantity",
            "SUM(CASE WHEN {$isUsage} THEN -stock_movements.quantity ELSE 0 END) as usage_quantity",
            "SUM(CASE WHEN {$isUsage} THEN -stock_movements.quantity * stock_lots.unit_cost ELSE 0 END) as usage_cost",
            "SUM(CASE WHEN (stock_movements.type = '{$out}' AND (stock_movements.reason_code IS NULL OR stock_movements.reason_code NOT IN ({$usage}))) OR stock_movements.type = '{$return}' THEN -stock_movements.quantity ELSE 0 END) as other_out_quantity",
            "SUM(CASE WHEN stock_movements.type IN ('{$transferIn}', '{$transferOut}') THEN stock_movements.quantity ELSE 0 END) as transfer_net",
            "SUM(CASE WHEN stock_movements.type = '{$countAdjust}' THEN stock_movements.quantity ELSE 0 END) as count_adjust",
        ]);
    }
}
