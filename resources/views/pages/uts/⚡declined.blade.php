<?php

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Services\UtsMatcher;
use App\Domain\Uts\Services\UtsReceiveService;
use App\Domain\Uts\Support\DeclinedReceipt;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Kurumun ÜTS'de "Almak istemiyorum" olarak işaretlediği Verme bildirimleri
 * (Aşama 57; rev. 1.99, 3.4.25). Stok yazma yetkisi olan, onay panelinden
 * geçerek "Almak istiyorum" der (3.4.23); bildirim yeniden Kabul
 * Bekleyenler'e düşer ve oradan kabul edilir.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    /** ÜTS'nin döndürdüğü sayfa başlangıç adresi; null ilk sayfa. */
    public ?string $offset = null;

    /** @var list<?string> Önceki sayfaların adresleri ("Önceki" için). */
    public array $previousOffsets = [];

    public ?string $undoingId = null;

    public function nextPage(string $offset): void
    {
        $this->previousOffsets[] = $this->offset;
        $this->offset = $offset;
    }

    public function previousPage(): void
    {
        $this->offset = array_pop($this->previousOffsets);
    }

    public function confirmUndo(string $notificationId): void
    {
        $this->authorize('stock_movement.create');

        $this->undoingId = $notificationId;
    }

    public function closeUndo(): void
    {
        $this->undoingId = null;
    }

    public function undo(): void
    {
        $this->authorize('stock_movement.create');

        $id = $this->undoingId;
        $this->undoingId = null;

        try {
            $receipt = collect(app(UtsClient::class)->declinedReceipts($this->offset)->items)->firstWhere('notificationId', $id);

            if ($receipt === null) {
                throw new UtsException('Bu bildirim artık almak istemediklerinizin arasında değil.');
            }

            app(UtsReceiveService::class)->undecline($receipt);
            session()->flash('status', 'İşaret kaldırıldı; bildirim yeniden Kabul Bekleyenler\'de.');
        } catch (UtsException $e) {
            $this->addError('undo', $e->getMessage());
        }
    }

    public function with(UtsClient $client, UtsMatcher $matcher): array
    {
        try {
            $page = $client->declinedReceipts($this->offset);
        } catch (UtsException $e) {
            return ['error' => $e->getMessage(), 'rows' => [], 'nextOffset' => null];
        }

        $rows = array_map(fn (DeclinedReceipt $receipt) => [
            'receipt' => $receipt,
            'product' => $matcher->match($receipt->uno, $receipt->lot, $receipt->serial)['product'],
        ], $page->items);

        return ['error' => null, 'rows' => $rows, 'nextOffset' => $page->nextOffset];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">ÜTS — Almak İstemediklerim</h1>
        <p class="text-[14px] text-ink-muted mt-1">ÜTS'de "Almak istemiyorum" olarak işaretlediğiniz Verme bildirimleri. Yanlışlıkla işaretlediyseniz ya da ürün sonradan geldiyse işareti kaldırın; bildirim Kabul Bekleyenler'e döner.</p>
        <x-uts-tabs active="uts.declined" />
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">
            {{ session('status') }}
            <a href="{{ route('uts.pending') }}" class="underline ml-1">Kabul Bekleyenler</a>
        </div>
    @endif

    @error('undo')
        <div class="mb-4 rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">{{ $message }}</div>
    @enderror

    @if ($error)
        <div class="rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">
            {{ $error }}
            @can('system_settings.viewAny')
                <a href="{{ route('settings.index') }}" class="underline ml-1">Ayarlar</a>
            @endcan
        </div>
    @elseif (count($rows) === 0 && $offset === null)
        <div class="border border-line rounded-lg bg-surface px-5 py-8 text-center text-[14px] text-ink-muted">"Almak istemiyorum" olarak işaretlediğiniz bildirim yok.</div>
    @else
        @php($canWrite = auth()->user()->can('stock_movement.create'))
        <div class="relative overflow-x-auto border border-line rounded-lg bg-surface">
            <table class="w-full text-[13px]">
                <thead class="text-left text-ink-muted border-b border-line">
                    <tr>
                        <th class="px-4 py-3 font-medium">Ürün</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Lot / Seri</th>
                        <th class="px-4 py-3 font-medium text-right">Adet</th>
                        <th class="px-4 py-3 font-medium">Kurum</th>
                        @if ($canWrite) <th class="px-4 py-3"><span class="sr-only">İşlem</span></th> @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($r = $row['receipt'])
                        <tr wire:key="declined-{{ $r->notificationId ?? $loop->index }}" class="border-b border-line last:border-0 align-top">
                            <td class="px-4 py-3">
                                <div class="text-ink">{{ $row['product']?->name ?? 'Ürün kartı yok' }}</div>
                                <div class="text-ink-muted">{{ $r->uno }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->lot ?? '—' }}@if ($r->serial) <div class="text-ink-muted">{{ $r->serial }}</div>@endif</td>
                            <td class="px-4 py-3 text-right">{{ $r->quantity ?? '—' }}</td>
                            <td class="px-4 py-3 min-w-[9rem]">{{ $r->institutionName ?? '—' }}@if ($r->senderCode) <div class="text-ink-muted">{{ $r->senderCode }}</div>@endif</td>
                            @if ($canWrite)
                                <td class="px-4 py-3 text-right">
                                    @if ($r->canUndo())
                                        <button type="button" wire:click="confirmUndo('{{ $r->notificationId }}')" class="whitespace-nowrap border border-line rounded-md px-3 py-1.5 text-[13px] text-ink hover:border-panel-900 transition-colors">Almak istiyorum</button>
                                    @else
                                        <span class="text-[12px] text-ink-muted">Gönderen işaretledi</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between mt-4 text-[13px]">
            <button wire:click="previousPage" @disabled($offset === null) class="border border-line rounded-md px-3 py-1.5 disabled:opacity-40">Önceki</button>
            <span class="text-ink-muted">Sayfa {{ count($previousOffsets) + 1 }}</span>
            <button @if ($nextOffset) wire:click="nextPage('{{ $nextOffset }}')" @endif @disabled($nextOffset === null) class="border border-line rounded-md px-3 py-1.5 disabled:opacity-40">Sonraki</button>
        </div>
    @endif

    <x-modal :show="$undoingId !== null" title="Almak istiyorum" on-close="closeUndo">
        <p class="text-[14px] text-ink">"Almak istemiyorum" işareti ÜTS'de kaldırılacak; bildirim yeniden Kabul Bekleyenler'e düşer.</p>
        <p class="text-[13px] text-ink-muted mt-2">Bu adım ürünü kabul etmez. Ürünü kutuyla karşılaştırdıktan sonra Kabul Bekleyenler'den ayrıca kabul edin.</p>
        <div class="flex items-center gap-3 mt-6">
            <button type="button" wire:click="undo" wire:loading.attr="disabled" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors disabled:opacity-50">İşareti kaldır</button>
            <button type="button" wire:click="closeUndo" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
        </div>
    </x-modal>
</div>
