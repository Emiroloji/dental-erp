<?php

use App\Domain\Access\Support\Module;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Transfer\Exceptions\TransferException;
use App\Domain\Transfer\Models\TransferRequest;
use App\Domain\Transfer\Services\TransferService;
use App\Domain\Transfer\Support\TransferPermissions;
use App\Domain\Transfer\Support\TransferStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Transfer detay sayfası: bildirimlerden ve listeden bağlantı verilir.
 * Durum geçişleri liste ekranıyla aynı TransferService ve TransferPermissions
 * üzerinden yapılır; kapsam dışı transfer 404 döner.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    public int $transferId;

    /** Red veya iptal gerekçesi. */
    public string $note = '';

    public function mount(int $transfer): void
    {
        $this->transferId = $this->findTransfer($transfer)->id;
    }

    public function approve(TransferService $transfers): void
    {
        $this->attempt(fn () => $transfers->approve($this->transfer(), auth()->user()), 'Talep onaylandı.');
    }

    public function prepare(TransferService $transfers): void
    {
        $this->attempt(fn () => $transfers->prepare($this->transfer(), auth()->user()), 'Transfer hazırlanıyor.');
    }

    public function ship(TransferService $transfers): void
    {
        $this->attempt(fn () => $transfers->ship($this->transfer(), auth()->user()), 'Transfer gönderildi; stok kaynak depodan düşüldü.');
    }

    public function receive(TransferService $transfers): void
    {
        $this->attempt(fn () => $transfers->receive($this->transfer(), auth()->user()), 'Transfer teslim alındı; stok hedef depoya eklendi.');
    }

    public function reject(TransferService $transfers): void
    {
        $this->validate(['note' => ['nullable', 'string', 'max:255']]);

        $this->attempt(fn () => $transfers->reject($this->transfer(), auth()->user(), $this->note ?: null), 'Talep reddedildi.');
    }

    public function cancel(TransferService $transfers): void
    {
        $this->validate(['note' => ['nullable', 'string', 'max:255']]);

        $this->attempt(fn () => $transfers->cancel($this->transfer(), auth()->user(), $this->note ?: null), 'Transfer iptal edildi.');
    }

    private function attempt(Closure $action, string $success): void
    {
        try {
            $action();
        } catch (TransferException|InsufficientStockException|InactiveLocationException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->reset('note');
        session()->flash('status', $success);
    }

    private function transfer(): TransferRequest
    {
        return $this->findTransfer($this->transferId);
    }

    private function findTransfer(int $transferId): TransferRequest
    {
        try {
            return TransferRequest::query()
                ->inBranches(auth()->user()->accessibleBranchIds(Module::Transfer))
                ->with(['product', 'fromWarehouse.branch', 'toWarehouse.branch', 'requester'])
                ->findOrFail($transferId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    public function with(TransferPermissions $permissions): array
    {
        $transfer = $this->transfer()->load(['events.actor', 'movements.lot', 'movements.serials']);
        $user = auth()->user();
        $status = $transfer->status;
        $allowed = fn (TransferStatus $target, string $ability) => $status->canTransitionTo($target) && $permissions->can($user, $ability, $transfer);

        return [
            'transfer' => $transfer,
            'canApprove' => $allowed(TransferStatus::Approved, 'approve'),
            'canReject' => $allowed(TransferStatus::Rejected, 'reject'),
            'canPrepare' => $allowed(TransferStatus::Preparing, 'prepare'),
            'canShip' => $allowed(TransferStatus::Shipped, 'ship'),
            'canReceive' => $allowed(TransferStatus::Received, 'receive'),
            'canCancel' => $allowed(TransferStatus::Cancelled, 'cancel'),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <a href="{{ route('transfers.index') }}" wire:navigate class="text-[13px] text-ink-muted hover:text-ink">← Transferler</a>
        <h1 class="mt-2 text-[22px] font-medium tracking-tight text-ink flex items-center gap-3">
            Transfer #{{ $transfer->id }}
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] font-normal {{ $transfer->status->badgeClasses() }}">{{ $transfer->status->label() }}</span>
        </h1>
        <p class="text-[14px] text-ink-muted mt-1">{{ $transfer->requester?->name ?? '—' }}, {{ $transfer->created_at->format('d.m.Y H:i') }}</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md bg-status-critical-bg border border-status-critical/20 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
    @endif

    <section class="border border-line rounded-lg bg-surface">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-px bg-line rounded-lg overflow-hidden text-[14px]">
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Ürün</dt><dd>{{ $transfer->product->name }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Miktar</dt><dd class="tabular-nums">{{ Number::format((float) $transfer->quantity, precision: 2) }} {{ $transfer->product->base_unit }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Kaynak (stoğu veren)</dt><dd>{{ $transfer->fromWarehouse->branch->name }} · {{ $transfer->fromWarehouse->name }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Hedef (ihtiyacı olan)</dt><dd>{{ $transfer->toWarehouse->branch->name }} · {{ $transfer->toWarehouse->name }}</dd></div>
            <div class="bg-surface px-5 py-3 sm:col-span-2"><dt class="text-[12px] text-ink-muted">Neden</dt><dd>{{ $transfer->reason ?? '—' }}</dd></div>
        </dl>
    </section>

    @if ($canApprove || $canReject || $canPrepare || $canShip || $canReceive || $canCancel)
        <section class="mt-6 border border-line rounded-lg bg-surface px-5 py-4 space-y-3">
            @if ($canReject || $canCancel)
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Gerekçe (red / iptal için)</label>
                    <input type="text" wire:model="note" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('note') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            @endif
            <div class="flex flex-wrap items-center gap-3">
                @if ($canApprove)
                    <button wire:click="approve" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Onayla</button>
                @endif
                @if ($canPrepare)
                    <button wire:click="prepare" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Hazırla</button>
                @endif
                @if ($canShip)
                    <button wire:click="ship" wire:confirm="Gönderildi olarak işaretlenince stok kaynak depodan düşülür. Devam edilsin mi?" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Gönder</button>
                @endif
                @if ($canReceive)
                    <button wire:click="receive" wire:confirm="Ürünleri teslim aldığını onaylıyor musun? Stok hedef depoya eklenecek." class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Teslim Al</button>
                @endif
                @if ($canReject)
                    <button wire:click="reject" wire:confirm="Talep reddedilsin mi? Stok değişmez." class="text-[14px] text-status-critical hover:underline">Reddet</button>
                @endif
                @if ($canCancel)
                    <button wire:click="cancel" wire:confirm="Transfer iptal edilsin mi? Gönderildiyse düşülen miktar kaynak depoya geri eklenir." class="text-[14px] text-ink-muted hover:text-status-critical hover:underline">İptal Et</button>
                @endif
            </div>
        </section>
    @endif

    @if ($transfer->movements->isNotEmpty())
        <section class="mt-10">
            <h2 class="text-[15px] font-medium text-ink mb-3">Stok Hareketleri</h2>
            <div class="border border-line rounded-lg bg-surface overflow-x-auto">
                <table class="w-full text-[14px]">
                    <thead>
                        <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                            <th class="px-5 py-3 font-medium">Tarih</th>
                            <th class="px-5 py-3 font-medium">Tip</th>
                            <th class="px-5 py-3 font-medium">Lot</th>
                            <th class="px-5 py-3 font-medium">SKT</th>
                            <th class="px-5 py-3 font-medium text-right">Miktar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($transfer->movements->sortBy('id') as $movement)
                            <tr>
                                <td class="px-5 py-3 text-ink-muted">{{ $movement->created_at->format('d.m.Y H:i') }}</td>
                                <td class="px-5 py-3">{{ $movement->type->label() }}</td>
                                <td class="px-5 py-3 font-mono text-[13px]">
                                    {{ $movement->lot->lot_no ?? '—' }}
                                    @if ($movement->serials->isNotEmpty())
                                        <div class="text-[12px] text-ink-muted">Seri: {{ $movement->serials->pluck('serial_no')->sort()->implode(', ') }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-3">{{ $movement->lot->expiry_date?->format('d.m.Y') ?? '—' }}</td>
                                <td class="px-5 py-3 text-right tabular-nums {{ (float) $movement->quantity < 0 ? 'text-status-critical' : 'text-status-good' }}">{{ (float) $movement->quantity > 0 ? '+' : '' }}{{ Number::format((float) $movement->quantity, precision: 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="mt-10">
        <h2 class="text-[15px] font-medium text-ink mb-3">Durum Geçmişi</h2>
        <ol class="border-l border-line ml-1 space-y-3">
            @foreach ($transfer->events->sortBy('id') as $event)
                <li class="pl-4 relative">
                    <span class="absolute -left-[5px] top-1.5 w-2 h-2 rounded-full bg-brand-500"></span>
                    <div class="text-[13px]"><span class="font-medium">{{ $event->status->label() }}</span> · {{ $event->actor?->name ?? 'Sistem' }}</div>
                    <div class="text-[12px] text-ink-muted">{{ $event->created_at->format('d.m.Y H:i') }}@if ($event->note) — {{ $event->note }}@endif</div>
                </li>
            @endforeach
        </ol>
    </section>
</div>
