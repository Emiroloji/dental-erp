<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Reporting\Services\ControlledLedgerService;
use App\Domain\Reporting\Support\ReportType;
use App\Http\Livewire\Concerns\WithReportFilters;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kontrollü Ürün Defteri (Faz 4 — Aşama 26).
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination, WithReportFilters;

    #[Url(except: '')]
    public string $product = '';

    public function updatingProduct(): void
    {
        $this->resetPage();
    }

    public function export(string $format): void
    {
        $this->queueReportExport(ReportType::Controlled, $format, ['product' => $this->product ?: null]);
    }

    public function with(ControlledLedgerService $ledger): array
    {
        return [
            'ledgers' => $ledger->ledger(auth()->user()->organization_id, $this->reportFilters(), $this->accessibleBranchIds(), $this->product ? (int) $this->product : null),
            'products' => Product::where('is_controlled', true)->orderBy('name')->get(['id', 'name']),
            'options' => $this->filterOptions(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Raporlar</h1>
            <p class="text-[14px] text-ink-muted mt-1">Kontrollü işaretli ürünlerin dönem içindeki tüm hareketleri, açılış ve kapanış bakiyesiyle. İptal edilen hareketler de iz olarak listelenir.</p>
        </div>
        <div class="flex gap-2 shrink-0">
            <button wire:click="export('xlsx')" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">Excel'e Aktar</button>
            <button wire:click="export('pdf')" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">PDF'e Aktar</button>
        </div>
    </div>

    <x-report-tabs />

    <x-report-filters :options="$options">
        <select wire:model.live="product" aria-label="Ürün" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Kontrollü Ürünler</option>
            @foreach ($products as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
            @endforeach
        </select>
    </x-report-filters>

    @forelse ($ledgers as $ledger)
        <section class="mb-6 border border-line rounded-lg bg-surface overflow-x-auto" wire:key="ledger-{{ $ledger['product']->id }}">
            <div class="px-5 py-3 flex flex-wrap items-center justify-between gap-2 border-b border-line">
                <h2 class="text-[15px] font-medium text-ink">{{ $ledger['product']->name }} <span class="text-[12px] text-ink-muted font-normal">{{ $ledger['product']->base_unit }}@if ($ledger['product']->license_number) · Ruhsat {{ $ledger['product']->license_number }}@endif</span></h2>
                <span class="text-[13px] text-ink-muted tabular-nums">Açılış {{ Number::format($ledger['opening'], maxPrecision: 2) }} → Kapanış <span class="text-ink font-medium">{{ Number::format($ledger['closing'], maxPrecision: 2) }}</span></span>
            </div>
            <table class="w-full text-[14px]">
                <thead>
                    <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                        <th class="px-5 py-2.5 font-medium">Tarih</th>
                        <th class="px-5 py-2.5 font-medium">Hareket</th>
                        <th class="px-5 py-2.5 font-medium">Lot</th>
                        <th class="px-5 py-2.5 font-medium">Şube · Depo</th>
                        <th class="px-5 py-2.5 font-medium text-right">Miktar</th>
                        <th class="px-5 py-2.5 font-medium text-right">Bakiye</th>
                        <th class="px-5 py-2.5 font-medium">Personel</th>
                        <th class="px-5 py-2.5 font-medium">Açıklama</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($ledger['entries'] as $entry)
                        @php $movement = $entry['movement']; @endphp
                        <tr>
                            <td class="px-5 py-2.5 text-ink-muted whitespace-nowrap">{{ $movement->created_at->format('d.m.Y H:i') }}</td>
                            <td class="px-5 py-2.5">{{ $movement->type->label() }}</td>
                            <td class="px-5 py-2.5 font-mono text-[13px]">{{ $movement->lot->lot_no ?? '—' }}</td>
                            <td class="px-5 py-2.5 text-[13px] text-ink-muted">{{ $movement->warehouse->branch->name }} · {{ $movement->warehouse->name }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums {{ (float) $movement->quantity < 0 ? 'text-status-critical' : 'text-status-good' }}">{{ (float) $movement->quantity > 0 ? '+' : '' }}{{ Number::format((float) $movement->quantity, maxPrecision: 2) }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums">{{ Number::format($entry['balance'], maxPrecision: 2) }}</td>
                            <td class="px-5 py-2.5 text-ink-muted">{{ $movement->actor?->name ?? '—' }}</td>
                            <td class="px-5 py-2.5 text-[13px] text-ink-muted">{{ $movement->reason }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-5 py-5 text-center text-ink-muted text-[13px]">Bu dönemde hareket yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @empty
        <div class="border border-line rounded-lg bg-surface px-5 py-8 text-center text-ink-muted text-[13px]">Kontrollü işaretli ürün yok. Ürün kartında "Kontrollü ürün" seçeneğini işaretleyin.</div>
    @endforelse

    @if ($ledgers->hasPages())
        <div class="mt-4">{{ $ledgers->links() }}</div>
    @endif
</div>
