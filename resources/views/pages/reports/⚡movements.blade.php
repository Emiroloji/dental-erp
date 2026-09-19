<?php

use App\Domain\Reporting\Services\MovementReportService;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Reporting\Support\ReportType;
use App\Http\Livewire\Concerns\WithReportFilters;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination, WithReportFilters;

    public string $type = '';

    public function updatingType(): void
    {
        $this->resetPage();
    }

    private function movementType(): ?string
    {
        return in_array($this->type, array_column(StockMovementType::cases(), 'value'), true) ? $this->type : null;
    }

    public function export(string $format): void
    {
        $this->queueReportExport(ReportType::Movements, $format, ['type' => $this->movementType()]);
    }

    public function with(MovementReportService $report): array
    {
        $organizationId = auth()->user()->organization_id;
        $filters = $this->reportFilters();

        return [
            'movements' => $report->withDetails($report->query($organizationId, $filters, $this->accessibleBranchIds(), $this->movementType()))->paginate(20),
            'totals' => $report->totals($organizationId, $filters, $this->accessibleBranchIds(), $this->movementType()),
            'types' => StockMovementType::cases(),
            'options' => $this->filterOptions(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Raporlar</h1>
            <p class="text-[14px] text-ink-muted mt-1">Seçilen dönemdeki tüm stok hareketleri: giriş, çıkış, transfer, sayım, iade ve iptaller.</p>
        </div>
        <div class="flex gap-2 shrink-0">
            <button wire:click="export('xlsx')" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">Excel'e Aktar</button>
            <button wire:click="export('pdf')" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">PDF'e Aktar</button>
        </div>
    </div>

    <x-report-tabs />

    <x-report-filters :options="$options">
        <select wire:model.live="type" aria-label="Hareket tipi" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Hareket Tipleri</option>
            @foreach ($types as $movementType)
                <option value="{{ $movementType->value }}">{{ $movementType->label() }}</option>
            @endforeach
        </select>
    </x-report-filters>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-line rounded-lg overflow-hidden border border-line mb-6">
        @foreach ($types as $movementType)
            @continue(! $totals['byType'][$movementType->value])
            <div class="bg-surface px-5 py-4">
                <p class="text-[12px] text-ink-muted">{{ $movementType->label() }}</p>
                <p class="text-[18px] font-medium tabular-nums">{{ $totals['byType'][$movementType->value] > 0 ? '+' : '' }}{{ Number::format($totals['byType'][$movementType->value], precision: 2) }}</p>
            </div>
        @endforeach
        <div class="bg-surface px-5 py-4">
            <p class="text-[12px] text-ink-muted">Net Değişim · {{ $totals['count'] }} hareket</p>
            <p class="text-[18px] font-medium tabular-nums {{ $totals['net'] < 0 ? 'text-status-critical' : 'text-status-good' }}">{{ $totals['net'] > 0 ? '+' : '' }}{{ Number::format($totals['net'], precision: 2) }}</p>
        </div>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-4 py-3 font-medium">Tarih</th>
                    <th class="px-4 py-3 font-medium">Tip</th>
                    <th class="px-4 py-3 font-medium">Ürün / Lot</th>
                    <th class="px-4 py-3 font-medium">Depo</th>
                    <th class="px-4 py-3 font-medium text-right">Miktar</th>
                    <th class="px-4 py-3 font-medium">Açıklama</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($movements as $movement)
                    <tr wire:key="report-movement-{{ $movement->id }}">
                        <td class="px-4 py-3 text-ink-muted text-[13px] whitespace-nowrap">{{ $movement->created_at->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">{{ $movement->type->label() }}</span></td>
                        <td class="px-4 py-3">
                            {{ $movement->lot->product->name }}
                            <div class="text-[12px] text-ink-muted font-mono">{{ $movement->lot->lot_no ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-[13px]">{{ $movement->warehouse->branch->name }} · {{ $movement->warehouse->name }}</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ (float) $movement->quantity < 0 ? 'text-status-critical' : 'text-status-good' }}">{{ (float) $movement->quantity > 0 ? '+' : '' }}{{ Number::format((float) $movement->quantity, precision: 2) }}</td>
                        <td class="px-4 py-3 text-[13px] text-ink-muted">{{ $movement->reason ?? '—' }} · {{ $movement->actor?->name ?? 'Sistem' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-ink-muted text-[13px]">Bu filtrelerle hareket bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($movements->hasPages())
            <div class="px-5 py-3 border-t border-line">{{ $movements->links() }}</div>
        @endif
    </section>
</div>
