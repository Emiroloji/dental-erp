<?php

use App\Domain\EInvoice\Models\EInvoice;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Gelen e-fatura detayı (Faz 6): başlık, tutarlar ve kalemler; ilaç/tıbbi
 * cihaz kalemlerinde GTIN, lot, seri ve tarih. Başka organizasyonun
 * faturası 404 döner.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    public int $invoiceId;

    public function mount(int $invoice): void
    {
        $this->invoiceId = EInvoice::findOrFail($invoice)->id;
    }

    public function with(): array
    {
        return [
            'invoice' => EInvoice::with('lines.identities')->findOrFail($this->invoiceId),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between gap-4">
        <div>
            <a href="{{ route('einvoice.index') }}" class="text-[13px] text-ink-muted hover:text-ink">Gelen e-Faturalar</a>
            <h1 class="text-[22px] font-medium tracking-tight text-ink mt-1 font-mono">{{ $invoice->invoice_number }}</h1>
            <p class="text-[14px] text-ink-muted mt-1">{{ $invoice->sender_name ?? '—' }}, {{ $invoice->senderIdLabel() }} {{ $invoice->sender_tax_number ?? '—' }}</p>
        </div>
        <a href="{{ route('einvoice.pdf', $invoice->id) }}" target="_blank" rel="noopener" class="mt-4 sm:mt-0 inline-flex border border-line rounded-md px-4 py-2 text-[14px] text-ink hover:border-panel-900">PDF'i aç</a>
    </div>

    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-line border border-line rounded-lg overflow-hidden mb-6 text-[14px]">
        <div class="bg-surface px-4 py-3"><dt class="text-[12px] text-ink-muted">Fatura tarihi</dt><dd class="text-ink mt-0.5">{{ $invoice->issue_date->format('d.m.Y') }}</dd></div>
        <div class="bg-surface px-4 py-3"><dt class="text-[12px] text-ink-muted">Senaryo / tip</dt><dd class="text-ink mt-0.5">{{ $invoice->profileLabel() }}, {{ $invoice->typeLabel() }}</dd></div>
        <div class="bg-surface px-4 py-3"><dt class="text-[12px] text-ink-muted">KDV hariç / KDV</dt><dd class="text-ink mt-0.5 tabular-nums">{{ $invoice->formatAmount($invoice->tax_exclusive) }} / {{ $invoice->formatAmount($invoice->tax_total) }}</dd></div>
        <div class="bg-surface px-4 py-3"><dt class="text-[12px] text-ink-muted">Ödenecek</dt><dd class="text-ink mt-0.5 font-medium tabular-nums">{{ $invoice->formatAmount($invoice->payable) }}</dd></div>
    </dl>

    <section class="relative border border-line rounded-lg bg-surface overflow-x-auto">
        <h2 class="px-5 py-4 border-b border-line text-[15px] font-medium text-ink">Kalemler</h2>
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">#</th>
                    <th class="px-5 py-3 font-medium">Ürün / hizmet</th>
                    <th class="px-5 py-3 font-medium text-right">Miktar</th>
                    <th class="px-5 py-3 font-medium text-right">Birim fiyat</th>
                    <th class="px-5 py-3 font-medium text-right">KDV</th>
                    <th class="px-5 py-3 font-medium text-right">Tutar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($invoice->lines as $line)
                    <tr wire:key="line-{{ $line->id }}" class="align-top">
                        <td class="px-5 py-3 text-ink-muted tabular-nums">{{ $line->line_no }}</td>
                        <td class="px-5 py-3 min-w-[16rem]">
                            <div class="text-ink">{{ $line->name }}</div>
                            @if ($line->seller_code) <div class="text-[12px] text-ink-muted">Satıcı kodu {{ $line->seller_code }}</div> @endif
                            @if ($line->identities->isNotEmpty())
                                <ul class="mt-2 space-y-1">
                                    @foreach ($line->identities as $identity)
                                        <li class="font-mono text-[12px] text-ink-muted flex flex-wrap gap-x-3">
                                            <span class="whitespace-nowrap">{{ $identity->gtin ?? '—' }}</span>
                                            @if ($identity->lot) <span class="whitespace-nowrap">Lot {{ $identity->lot }}</span> @endif
                                            @if ($identity->serial) <span class="whitespace-nowrap">Seri {{ $identity->serial }}</span> @endif
                                            @if ($identity->expiry_date) <span class="whitespace-nowrap">SKT {{ $identity->expiry_date->format('d.m.Y') }}</span> @endif
                                            @if ($identity->production_date) <span class="whitespace-nowrap">Üretim {{ $identity->production_date->format('d.m.Y') }}</span> @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">{{ Number::format((float) $line->quantity, maxPrecision: 4) }} <span class="text-ink-muted text-[12px]">{{ $line->unitLabel() }}</span></td>
                        <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">{{ $invoice->formatAmount($line->unit_price) }}</td>
                        <td class="px-5 py-3 text-right tabular-nums text-ink-muted">{{ $line->vat_rate !== null ? '%'.Number::format((float) $line->vat_rate, maxPrecision: 2) : '—' }}</td>
                        <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">{{ $invoice->formatAmount($line->line_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
