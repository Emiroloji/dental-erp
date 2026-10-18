<?php

use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\EInvoice\Services\EInvoiceConnectionService;
use App\Domain\EInvoice\Services\EInvoiceSyncService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Gelen e-faturalar (Faz 6): entegratörden her 30 dakikada çekilir; satın
 * alma yazma yetkisi olan "Şimdi çek" ile hemen çekebilir.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    #[Url(as: 'ara', except: '')]
    public string $search = '';

    #[Url(as: 'baslangic', except: '')]
    public string $from = '';

    #[Url(as: 'bitis', except: '')]
    public string $to = '';

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function syncNow(EInvoiceConnectionService $connections, EInvoiceSyncService $sync): void
    {
        Gate::authorize('purchasing.create');

        $connection = $connections->current();

        if (! $connection) {
            session()->flash('error', 'e-Fatura bağlantısı kurulmamış. Ayarlar ekranından entegratör API anahtarını girin.');

            return;
        }

        try {
            $result = $sync->sync($connection);
        } catch (EInvoiceException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $message = $result->created > 0 ? "{$result->created} yeni fatura alındı." : 'Yeni fatura yok.';
        if ($result->failed > 0) {
            $message .= " {$result->failed} fatura okunamadı; sonraki çekimde yeniden denenecek.";
        }

        session()->flash('status', $message);
        $this->resetPage();
    }

    public function with(EInvoiceConnectionService $connections): array
    {
        $search = trim($this->search);

        return [
            'connection' => $connections->current(),
            'invoices' => EInvoice::query()
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereLike('invoice_number', "%{$search}%")
                    ->orWhereLike('sender_name', "%{$search}%")
                    ->orWhere('sender_tax_number', $search)))
                ->when(filled($this->from), fn ($query) => $query->whereDate('issue_date', '>=', $this->from))
                ->when(filled($this->to), fn ($query) => $query->whereDate('issue_date', '<=', $this->to))
                ->withCount('lines')
                ->orderByDesc('issue_date')
                ->orderByDesc('id')
                ->paginate(25),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Gelen e-Faturalar</h1>
            <p class="text-[14px] text-ink-muted mt-1">Tedarikçilerin kliniğinize kestiği e-faturalar; entegratörden her 30 dakikada çekilir.
                @if ($connection?->last_synced_at) Son çekim {{ $connection->last_synced_at->format('d.m.Y H:i') }}. @endif
            </p>
        </div>
        @can('purchasing.create')
            @if ($connection)
                <button wire:click="syncNow" wire:loading.attr="disabled" class="mt-4 sm:mt-0 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="syncNow">Şimdi çek</span>
                    <span wire:loading wire:target="syncNow">Çekiliyor…</span>
                </button>
            @endif
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
    @endif

    @unless ($connection)
        <div class="mb-4 rounded-md border border-line bg-surface text-[13px] text-ink-muted px-4 py-3">
            e-Fatura bağlantısı kurulmamış.
            @can('einvoice_connection.update')
                <a href="{{ route('settings.index') }}" class="underline text-ink">Ayarlar</a> ekranından entegratör API anahtarını girin.
            @else
                Yöneticinizden bağlantıyı kurmasını isteyin.
            @endcan
        </div>
    @endunless

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-auto sm:flex-1 sm:max-w-sm">
            <label for="einvoice-search" class="sr-only">Ara</label>
            <input id="einvoice-search" type="search" wire:model.live.debounce.400ms="search" placeholder="Fatura no, gönderen ya da VKN" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
        </div>
        <div>
            <label for="einvoice-from" class="block text-[12px] text-ink-muted mb-1">Başlangıç</label>
            <input id="einvoice-from" type="date" wire:model.live="from" class="border border-line rounded-md px-3 py-2 text-[14px]">
        </div>
        <div>
            <label for="einvoice-to" class="block text-[12px] text-ink-muted mb-1">Bitiş</label>
            <input id="einvoice-to" type="date" wire:model.live="to" class="border border-line rounded-md px-3 py-2 text-[14px]">
        </div>
    </div>

    <section class="relative border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Tarih</th>
                    <th class="px-5 py-3 font-medium">Fatura no</th>
                    <th class="px-5 py-3 font-medium">Gönderen</th>
                    <th class="px-5 py-3 font-medium">Senaryo</th>
                    <th class="px-5 py-3 font-medium text-right">Kalem</th>
                    <th class="px-5 py-3 font-medium text-right">Tutar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($invoices as $invoice)
                    <tr wire:key="einvoice-{{ $invoice->id }}">
                        <td class="px-5 py-3 whitespace-nowrap text-ink-muted">{{ $invoice->issue_date->format('d.m.Y') }}</td>
                        <td class="px-5 py-3 whitespace-nowrap"><a href="{{ route('einvoice.show', $invoice->id) }}" class="text-ink hover:underline font-mono text-[13px]">{{ $invoice->invoice_number }}</a></td>
                        <td class="px-5 py-3">{{ $invoice->sender_name ?? '—' }}<div class="text-[12px] text-ink-muted">{{ $invoice->sender_tax_number }}</div></td>
                        <td class="px-5 py-3 text-ink-muted whitespace-nowrap">{{ $invoice->profileLabel() }}@if ($invoice->type !== 'SATIS')<div class="text-[12px]">{{ $invoice->typeLabel() }}</div>@endif</td>
                        <td class="px-5 py-3 text-right tabular-nums text-ink-muted">{{ $invoice->lines_count }}</td>
                        <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">{{ $invoice->formatAmount($invoice->payable) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-ink-muted text-[13px]">
                            {{ trim($search) !== '' || filled($from) || filled($to) ? 'Süzgeçlerle eşleşen fatura yok.' : 'Henüz gelen e-fatura yok. Bağlantı kuruluysa faturalar 30 dakika içinde burada görünür.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $invoices->links() }}</div>
</div>
