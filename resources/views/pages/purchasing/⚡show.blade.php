<?php

use App\Domain\Access\Support\Module;
use App\Domain\Purchasing\Exceptions\PurchasingException;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Domain\Purchasing\Support\PurchasingPermissions;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Satın alma siparişi detay sayfası: bildirimlerden ve listeden bağlantı
 * verilir. Durum geçişleri liste ekranıyla aynı PurchaseOrderService ve
 * PurchasingPermissions üzerinden yapılır. Teslim alma (lot/SKT ve belge
 * yükleme) listedeki teslim alma penceresinde yapılır; bu sayfa oraya
 * yönlendirir. Kapsam dışı sipariş 404 döner.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    public int $orderId;

    /** Red, iptal veya kalanı kapatma gerekçesi. */
    public string $actionNote = '';

    public function mount(int $order): void
    {
        $this->orderId = $this->findOrder($order)->id;
    }

    public function submit(PurchaseOrderService $orders): void
    {
        $this->attempt(fn () => $orders->submit($this->order(), auth()->user()), 'Talep onaya gönderildi.');
    }

    public function approve(PurchaseOrderService $orders): void
    {
        $this->attempt(fn () => $orders->approve($this->order(), auth()->user()), 'Talep onaylandı.');
    }

    public function markOrdered(PurchaseOrderService $orders): void
    {
        $this->attempt(fn () => $orders->markOrdered($this->order(), auth()->user()), 'Sipariş tedarikçiye verildi olarak işaretlendi.');
    }

    public function reject(PurchaseOrderService $orders): void
    {
        $this->attempt(fn () => $orders->reject($this->order(), auth()->user(), $this->note()), 'Talep reddedildi.');
    }

    public function cancel(PurchaseOrderService $orders): void
    {
        $this->attempt(fn () => $orders->cancel($this->order(), auth()->user(), $this->note()), 'Sipariş iptal edildi.');
    }

    public function closeRemaining(PurchaseOrderService $orders): void
    {
        $this->attempt(fn () => $orders->closeRemaining($this->order(), auth()->user(), $this->note()), 'Kalan miktar kapatıldı; sipariş tamamlandı.');
    }

    private function note(): ?string
    {
        $this->validate(['actionNote' => ['nullable', 'string', 'max:255']]);

        return $this->actionNote ?: null;
    }

    private function attempt(Closure $action, string $success): void
    {
        try {
            $action();
        } catch (PurchasingException|InactiveLocationException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->reset('actionNote');
        session()->flash('status', $success);
    }

    private function order(): PurchaseOrder
    {
        return $this->findOrder($this->orderId);
    }

    private function findOrder(int $orderId): PurchaseOrder
    {
        try {
            return PurchaseOrder::query()
                ->inBranches(auth()->user()->accessibleBranchIds(Module::Purchasing))
                ->with(['supplier', 'warehouse.branch', 'lines.product', 'requester'])
                ->findOrFail($orderId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    public function with(PurchasingPermissions $permissions): array
    {
        $order = $this->order()->load(['events.actor', 'receipts.lines.orderLine.product', 'receipts.receiver']);
        $user = auth()->user();
        $status = $order->status;
        $canManage = $permissions->canManage($user, $order->warehouse);
        $canApprove = $permissions->canApprove($user);

        return [
            'order' => $order,
            'canSubmit' => $status === PurchaseOrderStatus::Draft && $canManage,
            'canApprove' => $status === PurchaseOrderStatus::PendingApproval && $canApprove,
            'canMarkOrdered' => $status === PurchaseOrderStatus::Approved && $canManage,
            'canReceive' => $status->canReceive() && $canManage,
            'canCloseRemaining' => $status === PurchaseOrderStatus::PartiallyReceived && $canManage,
            'canCancel' => $status->canTransitionTo(PurchaseOrderStatus::Cancelled) && $canManage,
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <a href="{{ route('purchasing.index') }}" wire:navigate class="text-[13px] text-ink-muted hover:text-ink">← Satın Alma</a>
        <h1 class="mt-2 text-[22px] font-medium tracking-tight text-ink flex items-center gap-3">
            {{ $order->number() }}
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] font-normal {{ $order->status->badgeClasses() }}">{{ $order->status->label() }}</span>
        </h1>
        <p class="text-[14px] text-ink-muted mt-1">{{ $order->requester?->name ?? '—' }}, {{ $order->created_at->format('d.m.Y H:i') }}</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md bg-status-critical-bg border border-status-critical/20 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
    @endif

    <section class="border border-line rounded-lg bg-surface">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-px bg-line rounded-lg overflow-hidden text-[14px]">
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Tedarikçi</dt><dd>{{ $order->supplier->name }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Teslim Deposu</dt><dd>{{ $order->warehouse->branch->name }} · {{ $order->warehouse->name }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Sipariş Tarihi</dt><dd>{{ $order->ordered_at?->format('d.m.Y') ?? '—' }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Beklenen Teslim</dt><dd>{{ $order->expected_delivery_date?->format('d.m.Y') ?? '—' }}</dd></div>
            <div class="bg-surface px-5 py-3 sm:col-span-2"><dt class="text-[12px] text-ink-muted">Not</dt><dd>{{ $order->note ?? '—' }}</dd></div>
        </dl>
    </section>

    @if ($canSubmit || $canApprove || $canMarkOrdered || $canReceive || $canCloseRemaining || $canCancel)
        <section class="mt-6 border border-line rounded-lg bg-surface px-5 py-4 space-y-3">
            @if ($canApprove || $canCloseRemaining || $canCancel)
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Gerekçe (red, iptal veya kalanı kapatma için)</label>
                    <input type="text" wire:model="actionNote" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('actionNote') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            @endif
            <div class="flex flex-wrap items-center gap-3">
                @if ($canSubmit)
                    <button wire:click="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Onaya Gönder</button>
                @endif
                @if ($canApprove)
                    <button wire:click="approve" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Onayla</button>
                    <button wire:click="reject" wire:confirm="Talep reddedilsin mi?" class="text-[14px] text-status-critical hover:underline">Reddet</button>
                @endif
                @if ($canMarkOrdered)
                    <button wire:click="markOrdered" wire:confirm="Sipariş tedarikçiye verildi olarak işaretlensin mi?" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Sipariş Verildi</button>
                @endif
                @if ($canReceive)
                    <a href="{{ route('purchasing.index', ['teslim' => $order->id]) }}" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Teslim Al</a>
                @endif
                @if ($canCloseRemaining)
                    <button wire:click="closeRemaining" wire:confirm="Kalan miktar kapatılsın mı? Sipariş tamamlanır." class="text-[14px] text-ink-muted hover:underline">Kalanı Kapat</button>
                @endif
                @if ($canCancel)
                    <button wire:click="cancel" wire:confirm="Sipariş iptal edilsin mi?" class="text-[14px] text-ink-muted hover:text-status-critical hover:underline">İptal Et</button>
                @endif
            </div>
        </section>
    @endif

    <section class="mt-10">
        <h2 class="text-[15px] font-medium text-ink mb-3">Sipariş Kalemleri</h2>
        <div class="border border-line rounded-lg bg-surface overflow-x-auto">
            <table class="w-full text-[14px]">
                <thead>
                    <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                        <th class="px-5 py-3 font-medium">Ürün</th>
                        <th class="px-5 py-3 font-medium text-right">Sipariş</th>
                        <th class="px-5 py-3 font-medium text-right">Gelen</th>
                        <th class="px-5 py-3 font-medium text-right">Kalan</th>
                        <th class="px-5 py-3 font-medium text-right">Birim Fiyat</th>
                        <th class="px-5 py-3 font-medium text-right">Tutar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($order->lines as $line)
                        <tr>
                            <td class="px-5 py-3">{{ $line->product->name }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ Number::format((float) $line->quantity, precision: 2) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ Number::format((float) $line->received_quantity, precision: 2) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums {{ $line->remaining() > 0 ? 'text-status-warn' : 'text-ink-muted' }}">{{ Number::format($line->remaining(), precision: 2) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ Number::format((float) $line->unit_price, precision: 2) }} ₺</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ Number::format((float) $line->quantity * (float) $line->unit_price, precision: 2) }} ₺</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-line font-medium">
                        <td class="px-5 py-3" colspan="5">Toplam</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ Number::format($order->total(), precision: 2) }} ₺</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    @if ($order->receipts->isNotEmpty())
        <section class="mt-10">
            <h2 class="text-[15px] font-medium text-ink mb-3">Teslim Almalar</h2>
            <div class="space-y-3">
                @foreach ($order->receipts->sortBy('id') as $receipt)
                    <div class="border border-line rounded-lg bg-surface p-4 text-[13px]">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                            <span>{{ $receipt->created_at->format('d.m.Y H:i') }} · {{ $receipt->receiver?->name ?? '—' }}</span>
                            <span class="text-ink-muted">
                                Fatura: {{ $receipt->invoice_number ?? '—' }} · İrsaliye: {{ $receipt->delivery_note_number ?? '—' }}
                                @if ($receipt->document_path)
                                    · <a href="{{ route('purchasing.receipts.document', $receipt->id) }}" class="text-brand-600 hover:underline">{{ $receipt->document_name ?? 'Belge' }}</a>
                                @endif
                            </span>
                        </div>
                        <ul class="space-y-0.5 text-ink-muted">
                            @foreach ($receipt->lines as $receiptLine)
                                <li>{{ $receiptLine->orderLine->product->name }}: {{ Number::format((float) $receiptLine->quantity, precision: 2) }} · Lot {{ $receiptLine->lot_no ?? '—' }} · SKT {{ $receiptLine->expiry_date?->format('d.m.Y') ?? '—' }} · {{ Number::format((float) $receiptLine->unit_cost, precision: 2) }} ₺</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="mt-10">
        <h2 class="text-[15px] font-medium text-ink mb-3">Durum Geçmişi</h2>
        <ol class="border-l border-line ml-1 space-y-3">
            @foreach ($order->events->sortBy('id') as $event)
                <li class="pl-4 relative">
                    <span class="absolute -left-[5px] top-1.5 w-2 h-2 rounded-full bg-brand-500"></span>
                    <div class="text-[13px]"><span class="font-medium">{{ $event->status->label() }}</span> · {{ $event->actor?->name ?? 'Sistem' }}</div>
                    <div class="text-[12px] text-ink-muted">{{ $event->created_at->format('d.m.Y H:i') }}@if ($event->note) — {{ $event->note }}@endif</div>
                </li>
            @endforeach
        </ol>
    </section>
</div>
