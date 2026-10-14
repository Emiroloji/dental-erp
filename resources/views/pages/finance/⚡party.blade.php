<?php

use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public FinanceParty $party;

    public function mount(FinanceParty $party): void
    {
        $this->party = $party;
    }

    public function with(FinanceBalanceService $balances): array
    {
        $obligations = FinanceObligation::where('party_id', $this->party->id)->with('category')->orderByDesc('due_on')->orderByDesc('id')->get();

        return [
            'balance' => $balances->partyBalances([$this->party])[$this->party->id],
            'obligations' => $obligations,
            'paid' => $balances->paidAmounts($obligations),
            'today' => FinanceCalendar::today()->toDateString(),
            'entries' => FinanceEntry::where('party_id', $this->party->id)->with(['account', 'reversal'])->orderByDesc('occurred_on')->orderByDesc('id')->limit(100)->get(),
        ];
    }
};
?>

<div>
    <a href="{{ route('finance.parties') }}" wire:navigate class="inline-block mb-3 text-[13px] text-ink-muted hover:text-ink hover:underline">← Cariler</a>

    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">
            {{ $party->name }}
            @unless ($party->isActive())
                <span class="ml-1 align-middle inline-flex px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">Pasif</span>
            @endunless
        </h1>
        <p class="text-[14px] text-ink-muted mt-1">
            {{ $party->kind->label() }}
            @foreach (array_filter([$party->phone, $party->email, $party->tax_number ? 'Vergi no '.$party->tax_number : null, $party->default_term_days !== null ? 'Vade '.$party->default_term_days.' gün' : null]) as $detail)
                <span class="whitespace-nowrap">· {{ $detail }}</span>
            @endforeach
        </p>
        @if ($party->note)
            <p class="text-[13px] text-ink-muted mt-1">{{ $party->note }}</p>
        @endif
    </div>

    <div class="mb-8 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="border border-line rounded-lg bg-surface px-5 py-4">
            <div class="text-[13px] text-ink-muted">Alacak</div>
            <div class="mt-1 text-[22px] font-medium tabular-nums text-finance-in">{{ Money::format($balance['receivable']) }}</div>
        </div>
        <div class="border border-line rounded-lg bg-surface px-5 py-4">
            <div class="text-[13px] text-ink-muted">Borç</div>
            <div class="mt-1 text-[22px] font-medium tabular-nums text-finance-out">{{ Money::format($balance['payable']) }}</div>
        </div>
        <div class="border border-line rounded-lg bg-surface px-5 py-4">
            <div class="text-[13px] text-ink-muted">Net</div>
            <div @class(['mt-1 text-[22px] font-medium tabular-nums', 'text-finance-in' => $balance['net'] > 0, 'text-finance-out' => $balance['net'] < 0])>{{ Money::format($balance['net']) }}</div>
            <div class="text-[12px] text-ink-muted mt-0.5">{{ $balance['net'] > 0 ? 'Cari size borçlu' : ($balance['net'] < 0 ? 'Siz cariye borçlusunuz' : 'Hesap kapalı') }}</div>
        </div>
    </div>

    <h2 class="mb-2 text-[15px] font-medium text-ink">Ödeme ve tahsilat kayıtları</h2>
    <section class="mb-8 border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Vade</th>
                    <th class="px-3 sm:px-5 py-3 font-medium">Açıklama</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Yön</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Tutar</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium text-right">Kalan</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($obligations as $obligation)
                    @php($overdue = $obligation->status->isOpen() && $obligation->due_on->toDateString() < $today)
                    <tr wire:key="obligation-{{ $obligation->id }}" @class(['text-ink-muted' => $obligation->status === \App\Domain\Finance\Support\ObligationStatus::Cancelled])>
                        <td @class(['px-3 sm:px-5 py-3 whitespace-nowrap align-top', 'text-finance-out' => $overdue])>{{ $obligation->due_on->format('d.m.Y') }}</td>
                        <td class="px-3 sm:px-5 py-3 align-top">
                            {{ $obligation->description }}
                            @if ($obligation->document_no) <span class="text-ink-muted">· Belge {{ $obligation->document_no }}</span> @endif
                            <div class="sm:hidden text-[12px] text-ink-muted">{{ $obligation->direction->label() }} · {{ $obligation->status->label() }}</div>
                        </td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap align-top">{{ $obligation->direction->label() }}</td>
                        <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium align-top">{{ Money::format($obligation->amount) }}</td>
                        <td class="hidden sm:table-cell px-5 py-3 text-right whitespace-nowrap tabular-nums align-top">{{ $obligation->status === \App\Domain\Finance\Support\ObligationStatus::Cancelled ? '—' : Money::format($obligation->amount - $paid[$obligation->id]) }}</td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap align-top"><span class="inline-flex px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">{{ $obligation->status->label() }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-ink-muted text-[13px]">Bu cariye bağlı ödeme veya tahsilat kaydı yok.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <h2 class="mb-2 text-[15px] font-medium text-ink">Hareketler</h2>
    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Tarih</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Hesap</th>
                    <th class="px-3 sm:px-5 py-3 font-medium">Açıklama</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Tutar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($entries as $entry)
                    @php($reversed = $entry->reversal !== null)
                    <tr wire:key="entry-{{ $entry->id }}">
                        <td class="px-3 sm:px-5 py-3 whitespace-nowrap align-top">{{ $entry->occurred_on->format('d.m.Y') }}</td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap align-top">{{ $entry->account?->name }}</td>
                        <td class="px-3 sm:px-5 py-3 align-top">
                            <span class="font-medium">{{ $entry->label() }}</span>
                            @if ($reversed) <span class="text-[12px] px-1.5 rounded bg-line">İptal edildi</span> @endif
                            @if ($entry->description)
                                <div @class(['text-[13px]', 'line-through' => $reversed])>{{ $entry->description }}</div>
                            @endif
                            <div class="sm:hidden text-[12px] text-ink-muted">{{ $entry->account?->name }}</div>
                        </td>
                        <td @class([
                            'px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium align-top',
                            'line-through' => $reversed,
                            'text-finance-in' => ! $reversed && $entry->direction > 0,
                            'text-finance-out' => ! $reversed && $entry->direction < 0,
                        ])>{{ $entry->direction > 0 ? '+' : '−' }}{{ Money::format($entry->amount) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-8 text-center text-ink-muted text-[13px]">Bu cariyle henüz hareket yok.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
    @if ($entries->count() === 100)
        <p class="mt-2 text-[12px] text-ink-muted">Son 100 hareket gösteriliyor.</p>
    @endif
</div>
