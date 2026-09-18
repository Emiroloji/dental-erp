<?php

use App\Domain\Reporting\Services\PurchaseReportService;
use App\Domain\Reporting\Services\ReportDownloader;
use App\Http\Livewire\Concerns\WithReportFilters;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination, WithReportFilters;

    public function exportExcel(PurchaseReportService $report, ReportDownloader $downloader)
    {
        return $downloader->excel('Satın Alma ve İade Raporu', 'satin-alma-iade-raporu', $report->table(auth()->user()->organization_id, $this->reportFilters(), $this->accessibleBranchIds()));
    }

    public function exportPdf(PurchaseReportService $report, ReportDownloader $downloader)
    {
        return $downloader->pdf('Satın Alma ve İade Raporu', 'satin-alma-iade-raporu', $report->table(auth()->user()->organization_id, $this->reportFilters(), $this->accessibleBranchIds()), $this->reportFilters());
    }

    public function with(PurchaseReportService $report): array
    {
        $metrics = $report->metrics(auth()->user()->organization_id, $this->reportFilters(), $this->accessibleBranchIds());

        return [
            'rows' => $report->suppliers($metrics),
            'totals' => $report->totals($metrics),
            'options' => $this->filterOptions(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Raporlar</h1>
            <p class="text-[14px] text-ink-muted mt-1">Tedarikçi bazında dönem içi siparişler, teslim alınan (harcama), açık siparişler, iadeler ve kredi notları.</p>
        </div>
        <div class="flex gap-2 shrink-0">
            <button wire:click="exportExcel" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">Excel'e Aktar</button>
            <button wire:click="exportPdf" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">PDF'e Aktar</button>
        </div>
    </div>

    <x-report-tabs />

    <x-report-filters :options="$options" />

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-line rounded-lg overflow-hidden border border-line mb-6">
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Sipariş Tutarı · {{ $totals['order_count'] }} sipariş</p><p class="text-[18px] font-medium tabular-nums">{{ Number::format($totals['ordered_amount'], precision: 2) }} ₺</p></div>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Teslim Alınan (Harcama)</p><p class="text-[18px] font-medium tabular-nums">{{ Number::format($totals['received_amount'], precision: 2) }} ₺</p></div>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Açık Sipariş</p><p class="text-[18px] font-medium tabular-nums">{{ Number::format($totals['open_amount'], precision: 2) }} ₺</p></div>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Kredi Notu · {{ $totals['return_count'] }} iade</p><p class="text-[18px] font-medium tabular-nums">{{ Number::format($totals['credit_amount'], precision: 2) }} ₺</p></div>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-4 py-3 font-medium">Tedarikçi</th>
                    <th class="px-4 py-3 font-medium text-right">Sipariş</th>
                    <th class="px-4 py-3 font-medium text-right">Sipariş Tutarı</th>
                    <th class="px-4 py-3 font-medium text-right">Teslim Alınan</th>
                    <th class="px-4 py-3 font-medium text-right">Teslim Tutarı</th>
                    <th class="px-4 py-3 font-medium text-right">Açık Sipariş</th>
                    <th class="px-4 py-3 font-medium text-right">İade</th>
                    <th class="px-4 py-3 font-medium text-right">Kredi Notu</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $row)
                    <tr wire:key="supplier-{{ $row['supplier']->id }}">
                        <td class="px-4 py-3">{{ $row['supplier']->name }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['order_count'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ Number::format($row['ordered_amount'], precision: 2) }} ₺</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ Number::format($row['received_quantity'], precision: 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">{{ Number::format($row['received_amount'], precision: 2) }} ₺</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ Number::format($row['open_amount'], precision: 2) }} ₺</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['return_count'] }} · {{ Number::format($row['returned_quantity'], precision: 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ Number::format($row['credit_amount'], precision: 2) }} ₺</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-8 text-center text-ink-muted text-[13px]">Bu filtrelerle satın alma veya iade bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($rows->hasPages())
            <div class="px-5 py-3 border-t border-line">{{ $rows->links() }}</div>
        @endif
    </section>
</div>
