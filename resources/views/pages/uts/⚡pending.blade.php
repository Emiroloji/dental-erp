<?php

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Services\UtsMatcher;
use App\Domain\Uts\Services\UtsReceiveService;
use App\Domain\Uts\Support\PendingReceipt;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * ÜTS'de kuruma yapılmış ama henüz kabul edilmemiş Verme bildirimleri
 * (Aşama 33). Her satır yerel ürün/lot/seri/teslim kaydıyla eşleştirilir.
 * Stok yazma yetkisi olan, seçtiği satırları onay panelinden geçirip Alma
 * bildirimiyle kabul eder (Aşama 42). Kabul stoğa dokunmaz; stok girişi
 * satın alma teslim alma ekranından yapılır.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    #[Url(as: 'gonderen', except: '')]
    public string $sender = '';

    /** Belge (fatura/irsaliye) no süzgeci; gelen e-fatura detayından gelir (Aşama 45). */
    #[Url(as: 'belge', except: '')]
    public string $document = '';

    public int $page = 0;

    /** @var list<string> Seçili satırların ÜTS bildirim kodları (BID). */
    public array $selected = [];

    public bool $confirming = false;

    /** @var list<array<string, mixed>> Onay panelinde gösterilen satırlar. */
    public array $confirmRows = [];

    /** @var array<string, int|string> Lot takipli satırlarda kabul edilecek adet. */
    public array $quantities = [];

    /** @var array<string, array{ok: bool, text: string}> Gönderim sonuçları. */
    public array $results = [];

    /** "Almak istemiyorum" onayı bekleyen satır (Aşama 47). */
    public ?string $decliningId = null;

    public function updatedDocument(): void
    {
        $this->updatedSender();
    }

    public function updatedSender(): void
    {
        $this->page = 0;
        $this->selected = [];
    }

    public function nextPage(): void
    {
        $this->page++;
        $this->selected = [];
    }

    public function previousPage(): void
    {
        $this->page = max(0, $this->page - 1);
        $this->selected = [];
    }

    /**
     * Onay panelini açar: tek satır için kodu verilir, yoksa seçili satırlar.
     */
    public function confirm(?string $notificationId = null): void
    {
        $this->authorize('stock_movement.create');
        $client = app(UtsClient::class);

        try {
            $receipts = $this->currentReceipts($client);
        } catch (UtsException $e) {
            $this->addError('confirm', $e->getMessage());

            return;
        }

        $this->confirmRows = [];
        $this->quantities = [];
        $this->results = [];

        foreach ($notificationId !== null ? [$notificationId] : $this->selected as $id) {
            if ($receipt = $receipts[$id] ?? null) {
                $this->confirmRows[] = [
                    'id' => $id,
                    'name' => $receipt->brand ?? $receipt->uno,
                    'uno' => $receipt->uno,
                    'lot' => $receipt->lot,
                    'serial' => $receipt->serial,
                    'quantity' => $receipt->quantity,
                    'document' => $receipt->documentNo,
                    'sender' => $receipt->senderName,
                ];
                $this->quantities[$id] = $receipt->quantity ?? 1;
            }
        }

        $this->confirming = $this->confirmRows !== [];
    }

    public function closeConfirm(): void
    {
        $this->confirming = false;
        $this->confirmRows = [];
        $this->results = [];
        $this->selected = [];
    }

    /**
     * Onay panelindeki satırları sırayla kabul eder. Satırlar gönderim anında
     * ÜTS'den yeniden çekilir; bir satırın hatası diğerlerini durdurmaz.
     */
    public function send(): void
    {
        $this->authorize('stock_movement.create');
        $client = app(UtsClient::class);
        $service = app(UtsReceiveService::class);

        try {
            $receipts = $this->currentReceipts($client);
        } catch (UtsException $e) {
            $this->addError('confirm', $e->getMessage());

            return;
        }

        foreach (array_column($this->confirmRows, 'id') as $id) {
            $receipt = $receipts[$id] ?? null;

            if ($receipt === null) {
                $this->results[$id] = ['ok' => false, 'text' => 'Bu bildirim artık kabul bekleyenler arasında değil.'];

                continue;
            }

            $quantity = $receipt->serial === null && is_numeric($this->quantities[$id] ?? null) ? (int) $this->quantities[$id] : null;

            try {
                $record = $service->receive($receipt, $quantity);
                $this->results[$id] = ['ok' => true, 'text' => 'Kabul edildi. ÜTS bildirim kodu: '.$record->uts_notification_id];
            } catch (UtsException $e) {
                $this->results[$id] = ['ok' => false, 'text' => $e->getMessage()];
            }
        }

        $this->selected = [];
    }

    public function confirmDecline(string $notificationId): void
    {
        $this->authorize('stock_movement.create');

        $this->decliningId = $notificationId;
    }

    public function closeDecline(): void
    {
        $this->decliningId = null;
    }

    /**
     * Satırı ÜTS'de "Almak istemiyorum" olarak işaretler; satır kabul
     * bekleyenlerden kalkar. "Almak İstemediklerim" ekranından geri alınabilir.
     */
    public function decline(): void
    {
        $this->authorize('stock_movement.create');

        $id = $this->decliningId;
        $this->decliningId = null;

        try {
            $receipt = $this->currentReceipts(app(UtsClient::class))[$id] ?? null;

            if ($receipt === null) {
                throw new UtsException('Bu bildirim artık kabul bekleyenler arasında değil.');
            }

            app(UtsReceiveService::class)->decline($receipt);
            session()->flash('status', 'Bildirim ÜTS\'de "Almak istemiyorum" olarak işaretlendi.');
        } catch (UtsException $e) {
            $this->addError('confirm', $e->getMessage());
        }
    }

    /**
     * @return array<string, PendingReceipt> Bildirim koduna göre bu sayfadaki satırlar.
     */
    private function currentReceipts(UtsClient $client): array
    {
        $receipts = [];

        foreach ($client->pendingReceipts($this->senderCode(), $this->page, $this->documentNo())->items as $receipt) {
            if ($receipt->notificationId !== null) {
                $receipts[$receipt->notificationId] = $receipt;
            }
        }

        return $receipts;
    }

    private function documentNo(): ?string
    {
        return trim($this->document) !== '' ? trim($this->document) : null;
    }

    private function senderCode(): ?int
    {
        return ctype_digit(trim($this->sender)) ? (int) trim($this->sender) : null;
    }

    public function with(UtsClient $client, UtsMatcher $matcher): array
    {
        try {
            $result = $client->pendingReceipts($this->senderCode(), $this->page, $this->documentNo());
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
        <p class="text-[14px] text-ink-muted mt-1">Tedarikçilerin kurumunuza ÜTS'de bildirdiği, henüz kabul edilmemiş ürünler. Kabul edilen satırlar ÜTS'ye Alma bildirimi olarak gider; stok girişi satın alma teslim alma ekranından ayrıca yapılır.</p>
        <x-uts-tabs active="uts.pending" />
    </div>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2 w-full sm:w-auto">
            <input type="text" wire:model.live.debounce.500ms="sender" inputmode="numeric" placeholder="Gönderen kurum kodu" aria-label="Gönderen kurum kodu" class="border border-line rounded-md px-3 py-2 text-[14px] w-full sm:w-52">
            <input type="text" wire:model.live.debounce.500ms="document" placeholder="Belge (fatura) no" aria-label="Belge (fatura) no" class="border border-line rounded-md px-3 py-2 text-[14px] w-full sm:w-52">
        </div>

        @can('stock_movement.create')
            @if (count($selected) > 0)
                <button type="button" wire:click="confirm" class="bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    Seçilen {{ count($selected) }} bildirimi kabul et
                </button>
            @endif
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @error('confirm')
        <div class="mb-4 rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">{{ $message }}</div>
    @enderror

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
        @php($canAccept = auth()->user()->can('stock_movement.create'))
        <div class="relative overflow-x-auto border border-line rounded-lg bg-surface">
            <table class="w-full text-[13px]">
                <thead class="text-left text-ink-muted border-b border-line">
                    <tr>
                        @if ($canAccept) <th class="pl-4 py-3 w-8"><span class="sr-only">Seç</span></th> @endif
                        <th class="px-4 py-3 font-medium">Ürün</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Lot / Seri</th>
                        <th class="px-4 py-3 font-medium text-right">Adet</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Belge / zaman</th>
                        <th class="px-4 py-3 font-medium">Gönderen</th>
                        <th class="px-4 py-3 font-medium">Eşleşme</th>
                        @if ($canAccept) <th class="px-4 py-3"><span class="sr-only">İşlem</span></th> @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($r = $row['receipt'])
                        @php($m = $row['match'])
                        <tr wire:key="uts-{{ $r->notificationId ?? $loop->index }}" class="border-b border-line last:border-0 align-top">
                            @if ($canAccept)
                                <td class="pl-4 py-3">
                                    @if ($r->notificationId)
                                        <input type="checkbox" wire:model.live="selected" value="{{ $r->notificationId }}" aria-label="{{ $r->uno }} satırını seç" class="mt-0.5 rounded border-line text-brand-600 focus:ring-brand-500/30">
                                    @endif
                                </td>
                            @endif
                            <td class="px-4 py-3">
                                <div class="text-ink">{{ $m['product']?->name ?? $r->brand ?? '—' }}</div>
                                <div class="text-ink-muted">{{ $r->uno }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->lot ?? '—' }}@if ($r->serial) <div class="text-ink-muted">{{ $r->serial }}</div>@endif</td>
                            <td class="px-4 py-3 text-right">{{ $r->quantity ?? '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->documentNo ?? '—' }}<div class="text-ink-muted">{{ $r->notifiedAt ? rescue(fn () => \Illuminate\Support\Carbon::parse($r->notifiedAt)->format('d.m.Y H:i'), $r->notifiedAt, false) : '' }}</div></td>
                            <td class="px-4 py-3 min-w-[9rem]">{{ $r->senderName ?? '—' }}@if ($r->senderCode) <div class="text-ink-muted">{{ $r->senderCode }}</div>@endif</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col items-start gap-1">
                                    @if ($m['product'])
                                        <span class="whitespace-nowrap rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Ürün bizde var</span>
                                    @else
                                        <span class="whitespace-nowrap rounded-full bg-amber-100 text-amber-700 px-2 py-0.5 text-[12px]">Ürün bizde yok</span>
                                    @endif
                                    @if ($m['lot']) <span class="whitespace-nowrap rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Lot kayıtlı</span> @endif
                                    @if ($m['serial']) <span class="whitespace-nowrap rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Seri kayıtlı</span> @endif
                                    @if ($m['receipt']) <a href="{{ route('purchasing.show', $m['receipt']->purchase_order_id) }}" class="whitespace-nowrap rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px] underline">Teslim kaydı var</a> @endif
                                </div>
                            </td>
                            @if ($canAccept)
                                <td class="px-4 py-3 text-right">
                                    @if ($r->notificationId)
                                        <button type="button" wire:click="confirm('{{ $r->notificationId }}')" class="whitespace-nowrap border border-line rounded-md px-3 py-1.5 text-[13px] text-ink hover:border-panel-900 transition-colors">Kabul et</button>
                                        <button type="button" wire:click="confirmDecline('{{ $r->notificationId }}')" class="block ml-auto mt-1.5 whitespace-nowrap text-[12px] text-ink-muted hover:text-ink hover:underline">Almak istemiyorum</button>
                                    @endif
                                </td>
                            @endif
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

    <x-modal :show="$confirming" title="ÜTS'de kabul et" on-close="closeConfirm">
        @if ($results === [])
            <div class="rounded-md border border-status-critical/30 bg-red-50 px-4 py-3 mb-5">
                <p class="text-[14px] font-medium text-status-critical">Bu işlem geri alınamaz.</p>
                <p class="text-[13px] text-ink mt-1">ÜTS'de Alma bildiriminin iptali yoktur. Yanlış kabul edilen ürün ancak gönderene iade bildirimiyle geri verilebilir. Ürünleri, lotları ve adetleri kutularla karşılaştırın.</p>
            </div>
            <p class="text-[13px] text-ink-muted mb-3">{{ count($confirmRows) }} bildirim kabul edilecek.</p>
        @endif

        <ul class="divide-y divide-line border border-line rounded-md">
            @foreach ($confirmRows as $row)
                <li wire:key="confirm-{{ $row['id'] }}" class="px-4 py-3 flex flex-wrap items-start justify-between gap-3 text-[13px]">
                    <div class="min-w-0">
                        <div class="text-ink font-medium">{{ $row['name'] }}</div>
                        <div class="text-ink-muted">
                            {{ $row['uno'] }}
                            @if ($row['lot']) <span class="ml-2">Lot {{ $row['lot'] }}</span> @endif
                            @if ($row['serial']) <span class="ml-2">Seri {{ $row['serial'] }}</span> @endif
                        </div>
                        <div class="text-ink-muted">Belge {{ $row['document'] ?? '—' }}@if ($row['sender']), {{ $row['sender'] }}@endif</div>
                        @if ($result = $results[$row['id']] ?? null)
                            <div class="mt-1.5 {{ $result['ok'] ? 'text-brand-600' : 'text-status-critical' }}">{{ $result['text'] }}</div>
                        @endif
                    </div>
                    <div class="shrink-0 text-right">
                        @if ($results === [] && ! $row['serial'] && ($row['quantity'] ?? 1) > 1)
                            <label class="block text-[12px] text-ink-muted mb-1" for="qty-{{ $row['id'] }}">Kabul edilen adet</label>
                            <input id="qty-{{ $row['id'] }}" type="number" min="1" max="{{ $row['quantity'] }}" wire:model="quantities.{{ $row['id'] }}" class="w-20 border border-line rounded-md px-2 py-1 text-[14px] text-right focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                            <div class="text-[12px] text-ink-muted mt-1">Verilen: {{ $row['quantity'] }}</div>
                        @elseif ($results !== [] && ! $row['serial'])
                            <div class="text-ink">{{ $quantities[$row['id']] ?? $row['quantity'] }} adet</div>
                        @else
                            <div class="text-ink">{{ $row['quantity'] ?? 1 }} adet</div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="flex items-center gap-3 mt-6">
            @if ($results === [])
                <button type="button" wire:click="send" wire:loading.attr="disabled" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="send">ÜTS'de kabul et</span>
                    <span wire:loading wire:target="send">Gönderiliyor…</span>
                </button>
                <button type="button" wire:click="closeConfirm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            @else
                <button type="button" wire:click="closeConfirm" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kapat</button>
                <a href="{{ route('uts.history') }}" class="text-[14px] text-ink-muted hover:text-ink underline">Gönderim geçmişi</a>
            @endif
        </div>
    </x-modal>

    <x-modal :show="$decliningId !== null" title="Almak istemiyorum" on-close="closeDecline">
        <p class="text-[14px] text-ink">Bu Verme bildirimi ÜTS'de "Almak istemiyorum" olarak işaretlenecek ve kabul bekleyenlerden kalkacak. Ürün kliniğinize gelmediyse ya da yanlış gönderildiyse kullanın.</p>
        <p class="text-[13px] text-ink-muted mt-2">Gerekirse "Almak İstemediklerim" sekmesinden geri alabilirsiniz; bildirim yeniden kabul bekleyenlere düşer.</p>
        <div class="flex items-center gap-3 mt-6">
            <button type="button" wire:click="decline" wire:loading.attr="disabled" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors disabled:opacity-50">İşaretle</button>
            <button type="button" wire:click="closeDecline" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
        </div>
    </x-modal>
</div>
