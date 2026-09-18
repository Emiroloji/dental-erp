<?php

use App\Domain\Reporting\Services\ReportDownloader;
use App\Domain\Reporting\Services\UsageReportService;
use App\Http\Livewire\Concerns\WithReportFilters;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination, WithReportFilters;

    public function exportExcel(UsageReportService $report, ReportDownloader $downloader)
    {
        return $downloader->excel('Kullanım ve Maliyet Raporu', 'kullanim-maliyet-raporu', $report->table(auth()->user()->organization_id, $this->reportFilters(), $this->accessibleBranchIds()));
    }

    public function exportPdf(UsageReportService $report, ReportDownloader $downloader)
    {
        return $downloader->pdf('Kullanım ve Maliyet Raporu', 'kullanim-maliyet-raporu', $report->table(auth()->user()->organization_id, $this->reportFilters(), $this->accessibleBranchIds()), $this->reportFilters());
    }

    public function with(UsageReportService $report): array
    {
        $organizationId = auth()->user()->organization_id;
        $filters = $this->reportFilters();

        $rows = $report->query($organizationId, $filters, $this->accessibleBranchIds())->paginate(20);
        $rows->setCollection($rows->getCollection()->map(fn (object $row) => ['name' => $row->product_name, 'unit' => $row->base_unit, ...$report->normalize($row)]));

        return [
            'rows' => $rows,
            'totals' => $report->totals($organizationId, $filters, $this->accessibleBranchIds()),
            'options' => $this->filterOptions(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Raporlar</h1>
            <p class="text-[14px] text-ink-muted mt-1">Ürün bazında dönem içi giriş, kullanım ve maliyeti; hasar, SKT ve iade gibi diğer çıkışlar kullanımdan ayrı tutulur.</p>
        </div>
        <div class="flex gap-2 shrink-0">
            <button wire:click="exportExcel" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">Excel'e Aktar</button>
            <button wire:click="exportPdf" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">PDF'e Aktar</button>
        </div>
    </div>

    <x-report-tabs />

    <x-report-filters :options="$options" />

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-line rounded-lg overflow-hidden border border-line mb-6">
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Giriş</p><p class="text-[18px] font-medium tabular-nums">{{ Number::format($totals['in_quantity'], precision: 2) }}</p></div>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Kullanım</p><p class="text-[18px] font-medium tabular-nums">{{ Number::format($totals['usage_quantity'], precision: 2) }}</p></div>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Kullanım Maliyeti</p><p class="text-[18px] font-medium tabular-nums">{{ Number::format($totals['usage_cost'], precision: 2) }} ₺</p></div>
        <div class="bg-surface px-5 py-4"><p class="text-[12px] text-ink-muted">Diğer Çıkışlar</p><p class="text-[18px] font-medium tabular-nums">{{ Number::format($totals['other_out_quantity'], precision: 2) }}</p></div>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-4 py-3 font-medium">Ürün</th>
                    <th class="px-4 py-3 font-medium text-right">Giriş</th>
                    <th class="px-4 py-3 font-medium text-right">Kullanım</th>
                    <th class="px-4 py-3 font-medium text-right">Kullanım Maliyeti</th>
                    <th class="px-4 py-3 font-medium text-right">Diğer Çıkış</th>
                    <th class="px-4 py-3 font-medium text-right">Transfer (net)</th>
                    <th class="px-4 py-3 font-medium text-right">Sayım Farkı</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $row)
                    <tr wire:key="usage-{{ $loop->index }}-{{ $row['name'] }}">
                        <td class="px-4 py-3">{{ $row['name'] }} <span class="text-[12px] text-ink-muted">({{ $row['unit'] }})</span></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ Number::format($row['in_quantity'], precision: 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">{{ Number::format($row['usage_quantity'], precision: 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ Number::format($row['usage_cost'], precision: 2) }} ₺</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ Number::format($row['other_out_quantity'], precision: 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-muted">{{ Number::format($row['transfer_net'], precision: 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-muted">{{ Number::format($row['count_adjust'], precision: 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-ink-muted text-[13px]">Bu filtrelerle hareket bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($rows->hasPages())
            <div class="px-5 py-3 border-t border-line">{{ $rows->links() }}</div>
        @endif
    </section>
</div>
