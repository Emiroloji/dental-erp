<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountLine;
use Illuminate\Support\Collection;

/**
 * Sayım raporu (proje.md Bölüm 10 kayıt alanları: sayım tarihi, depo, ürün/lot
 * bazlı sistem ve sayılan miktar, fark, neden, sayan, onaylayan).
 */
class StockCountReportService
{
    /**
     * @return array{headings: array<int, string>, rows: Collection<int, array<int, mixed>>, totals: array<int, mixed>}
     */
    public function table(StockCount $count): array
    {
        $lines = $count->lines()
            ->with(['product', 'lot'])
            ->join('products', 'stock_count_lines.product_id', '=', 'products.id')
            ->orderBy('products.name')
            ->orderBy('stock_count_lines.id')
            ->select('stock_count_lines.*')
            ->get();

        return [
            'headings' => ['Ürün', 'Lot', 'SKT', 'Sistem', 'Sayılan', 'Fark', 'Neden', 'Açıklama'],
            'rows' => $lines->map(fn (StockCountLine $line) => [
                $line->product->name,
                $line->lot->lot_no,
                $line->lot->expiry_date?->format('d.m.Y'),
                (float) $line->system_quantity,
                $line->isCounted() ? (float) $line->counted_quantity : null,
                $line->difference(),
                $line->reason?->label(),
                $line->note,
            ]),
            'totals' => ['Toplam', '', '', (float) $lines->sum('system_quantity'), (float) $lines->filter->isCounted()->sum('counted_quantity'), (float) $lines->filter->isCounted()->sum(fn (StockCountLine $line) => $line->difference()), '', ''],
        ];
    }

    public function title(StockCount $count): string
    {
        $count->loadMissing(['warehouse.branch', 'starter', 'events.actor']);
        $approval = $count->events->firstWhere('status.value', 'approved');

        return "Sayım Raporu {$count->number()} — {$count->warehouse->branch->name} {$count->warehouse->name}, "
            .$count->created_at->format('d.m.Y').', sayan: '.($count->starter?->name ?? '—')
            .', durum: '.$count->status->label()
            .($approval ? ', onaylayan: '.($approval->actor?->name ?? '—') : '');
    }
}
