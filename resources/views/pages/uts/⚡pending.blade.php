<?php

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Services\UtsMatcher;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * ÜTS'de kuruma yapılmış ama henüz kabul edilmemiş Verme bildirimleri
 * (Aşama 33, salt okunur). Her satır yerel ürün/lot/seri/teslim kaydıyla
 * eşleştirilir; kabul (Alma bildirimi) Aşama B'dir.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    #[Url(as: 'gonderen', except: '')]
    public string $sender = '';

    public int $page = 0;

    public function updatedSender(): void
    {
        $this->page = 0;
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function previousPage(): void
    {
        $this->page = max(0, $this->page - 1);
    }

    public function with(UtsClient $client, UtsMatcher $matcher): array
    {
        $senderCode = ctype_digit(trim($this->sender)) ? (int) trim($this->sender) : null;

        try {
            $result = $client->pendingReceipts($senderCode, $this->page);
        } catch (UtsException $e) {
            return ['error' => $e->getMessage(), 'rows' => [], 'hasMore' => false];
        }

        $rows = array_map(fn ($receipt) => [
            'receipt' => $receipt,
            'match' => $matcher->match($receipt->uno, $receipt->lot, $receipt->serial, $receipt->documentNo),
        ], $result->items);

        return ['error' => null, 'rows' => $rows, 'hasMore' => $result->hasMore];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">ÜTS — Kabul Bekleyenler</h1>
        <p class="text-[14px] text-ink-muted mt-1">Tedarikçilerin kurumunuza ÜTS'de bildirdiği, henüz kabul edilmemiş ürünler. Bu ekran yalnızca görüntüler; ÜTS'ye bildirim göndermez.</p>
        <nav class="flex gap-4 mt-4 text-[14px]">
            <a href="{{ route('uts.pending') }}" class="font-medium text-ink border-b-2 border-brand-500 pb-1">Kabul Bekleyenler</a>
            <a href="{{ route('uts.verify') }}" class="text-ink-muted hover:text-ink pb-1">Lot/Seri Doğrula</a>
        </nav>
    </div>

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.500ms="sender" inputmode="numeric" placeholder="Gönderen kurum kodu (isteğe bağlı)" class="border border-line rounded-md px-3 py-2 text-[14px] w-full max-w-xs">
    </div>

    @if ($error)
        <div class="rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">
            {{ $error }}
            @can('system_settings.viewAny')
                <a href="{{ route('settings.index') }}" class="underline ml-1">Ayarlar</a>
            @endcan
        </div>
    @elseif (count($rows) === 0)
        <div class="border border-line rounded-lg bg-surface px-5 py-8 text-center text-[14px] text-ink-muted">Kabul bekleyen bildirim yok.</div>
    @else
        <div class="overflow-x-auto border border-line rounded-lg bg-surface">
            <table class="w-full text-[13px]">
                <thead class="text-left text-ink-muted border-b border-line">
                    <tr>
                        <th class="px-4 py-3 font-medium">Ürün</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Lot / Seri</th>
                        <th class="px-4 py-3 font-medium text-right">Adet</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Belge no</th>
                        <th class="px-4 py-3 font-medium">Gönderen</th>
                        <th class="px-4 py-3 font-medium">Zaman</th>
                        <th class="px-4 py-3 font-medium">Eşleşme</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($r = $row['receipt'])
                        @php($m = $row['match'])
                        <tr class="border-b border-line last:border-0 align-top">
                            <td class="px-4 py-3">
                                <div class="text-ink">{{ $m['product']?->name ?? $r->brand ?? '—' }}</div>
                                <div class="text-ink-muted">{{ $r->uno }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->lot ?? '—' }}@if ($r->serial) <div class="text-ink-muted">{{ $r->serial }}</div>@endif</td>
                            <td class="px-4 py-3 text-right">{{ $r->quantity ?? '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->documentNo ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $r->senderName ?? '—' }}@if ($r->senderCode) <div class="text-ink-muted">{{ $r->senderCode }}</div>@endif</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->notifiedAt ? rescue(fn () => \Illuminate\Support\Carbon::parse($r->notifiedAt)->format('d.m.Y H:i'), $r->notifiedAt, false) : '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @if ($m['product'])
                                        <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Ürün bizde var</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 text-amber-700 px-2 py-0.5 text-[12px]">Ürün bizde yok</span>
                                    @endif
                                    @if ($m['lot']) <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Lot kayıtlı</span> @endif
                                    @if ($m['serial']) <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Seri kayıtlı</span> @endif
                                    @if ($m['receipt']) <a href="{{ route('purchasing.show', $m['receipt']->purchase_order_id) }}" class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px] underline">Teslim kaydı var</a> @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between mt-4 text-[13px]">
            <button wire:click="previousPage" @disabled($page === 0) class="border border-line rounded-md px-3 py-1.5 disabled:opacity-40">Önceki</button>
            <span class="text-ink-muted">Sayfa {{ $page + 1 }}</span>
            <button wire:click="nextPage" @disabled(! $hasMore) class="border border-line rounded-md px-3 py-1.5 disabled:opacity-40">Sonraki</button>
        </div>
    @endif
</div>
