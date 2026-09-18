<?php

use App\Domain\Returns\Exceptions\ReturnException;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Services\ReturnService;
use App\Domain\Returns\Support\ReturnPermissions;
use App\Domain\Returns\Support\ReturnStatus;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Models\StockMovement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public int $returnId;

    /** Onay/ret gerekçesi, kargo bilgisi veya tedarikçi yanıtı. */
    public string $actionNote = '';

    public string $credit_note_number = '';

    public string $credit_amount = '';

    public function mount(int $return): void
    {
        $this->returnId = $this->findReturn($return)->id;
    }

    public function approve(ReturnService $returns): void
    {
        $this->attempt(fn () => $returns->approve($this->return(), auth()->user()), 'İade talebi onaylandı; ürün kargoya verilebilir.');
    }

    public function reject(ReturnService $returns): void
    {
        $this->attempt(fn () => $returns->reject($this->return(), auth()->user(), $this->actionNote), 'İade talebi reddedildi.');
    }

    public function ship(ReturnService $returns): void
    {
        $this->attempt(fn () => $returns->ship($this->return(), auth()->user(), $this->actionNote), 'İade kargoya verildi; miktar lottan düşüldü.');
    }

    public function supplierApprove(ReturnService $returns): void
    {
        $this->attempt(fn () => $returns->supplierApprove($this->return(), auth()->user(), $this->actionNote), 'Tedarikçi onayı kaydedildi.');
    }

    public function supplierReject(ReturnService $returns): void
    {
        $this->attempt(fn () => $returns->supplierReject($this->return(), auth()->user(), $this->actionNote), 'Tedarikçi iadeyi reddetti; ürün stoğa geri eklendi.');
    }

    public function complete(ReturnService $returns): void
    {
        $this->validate([
            'credit_note_number' => ['nullable', 'string', 'max:255'],
            'credit_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->attempt(fn () => $returns->complete(
            $this->return(), auth()->user(),
            $this->credit_note_number ?: null,
            $this->credit_amount === '' ? null : (float) $this->credit_amount,
            $this->actionNote,
        ), 'İade tamamlandı.');
    }

    private function attempt(Closure $action, string $success): void
    {
        try {
            $action();
        } catch (ReturnException|InsufficientStockException|InactiveLocationException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->reset(['actionNote', 'credit_note_number', 'credit_amount']);
        session()->flash('status', $success);
    }

    private function return(): SupplierReturn
    {
        return $this->findReturn($this->returnId);
    }

    private function findReturn(int $returnId): SupplierReturn
    {
        try {
            $return = SupplierReturn::with('warehouse.branch')->findOrFail($returnId);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        abort_unless(app(ReturnPermissions::class)->canView(auth()->user(), $return), 404);

        return $return;
    }

    public function with(ReturnPermissions $permissions): array
    {
        $return = $this->return()->load(['product', 'lot', 'supplier', 'purchaseOrder', 'requester', 'events.actor']);
        $user = auth()->user();
        $canManage = $permissions->canManage($user, $return->warehouse);
        $canApprove = $permissions->canApprove($user);

        $movementIds = $return->movements()->pluck('id');

        return [
            'return' => $return,
            'movements' => StockMovement::query()
                ->where(fn ($query) => $query
                    ->whereIn('id', $movementIds)
                    ->orWhere(fn ($cancels) => $cancels->where('related_entity_type', StockMovement::class)->whereIn('related_entity_id', $movementIds)))
                ->with('actor')
                ->orderBy('id')
                ->get(),
            'canApprove' => $canApprove && $return->status === ReturnStatus::Requested,
            'canReject' => $canApprove && in_array($return->status, [ReturnStatus::Requested, ReturnStatus::Approved], true),
            'canShip' => $canManage && $return->status === ReturnStatus::Approved,
            'canSupplierDecide' => $canManage && $return->status === ReturnStatus::Shipped,
            'canComplete' => $canManage && $return->status === ReturnStatus::SupplierApproved,
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <a href="{{ route('returns.index') }}" class="text-[13px] text-ink-muted hover:text-ink">← İadeler</a>
        <h1 class="mt-2 text-[22px] font-medium tracking-tight text-ink flex items-center gap-3">
            {{ $return->number() }}
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] font-normal {{ $return->status->badgeClasses() }}">{{ $return->status->label() }}</span>
        </h1>
        <p class="text-[14px] text-ink-muted mt-1">{{ $return->warehouse->branch->name }} · {{ $return->warehouse->name }} — {{ $return->requester?->name ?? '—' }}, {{ $return->created_at->format('d.m.Y H:i') }}</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md bg-status-critical-bg border border-status-critical/20 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
    @endif

    <section class="border border-line rounded-lg bg-surface">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-px bg-line rounded-lg overflow-hidden text-[14px]">
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Ürün</dt><dd>{{ $return->product->name }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Lot / SKT</dt><dd><span class="font-mono">{{ $return->lot->lot_no ?? '—' }}</span> · {{ $return->lot->expiry_date?->format('d.m.Y') ?? '—' }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Miktar</dt><dd class="tabular-nums">{{ Number::format((float) $return->quantity, precision: 2) }} {{ $return->product->base_unit }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">İade Nedeni</dt><dd>{{ $return->reasonText() }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Tedarikçi</dt><dd>{{ $return->supplier->name }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">İlgili Sipariş / Fatura</dt><dd>{{ $return->purchaseOrder?->number() ?? '—' }} · {{ $return->invoice_number ?? '—' }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Tedarikçi Yanıtı</dt><dd>{{ $return->supplier_response ?? '—' }}</dd></div>
            <div class="bg-surface px-5 py-3"><dt class="text-[12px] text-ink-muted">Kredi Notu</dt><dd>{{ $return->credit_note_number ?? '—' }}@if ($return->credit_amount !== null) · <span class="tabular-nums">{{ Number::format((float) $return->credit_amount, precision: 2) }} ₺</span>@endif</dd></div>
        </dl>
    </section>

    @if ($canApprove || $canReject || $canShip || $canSupplierDecide || $canComplete)
        <section class="mt-6 border border-line rounded-lg bg-surface px-5 py-4 space-y-3">
            @if ($canComplete)
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Kredi Notu No</label>
                        <input type="text" wire:model="credit_note_number" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Kredi Tutarı (₺)</label>
                        <input type="number" step="0.01" min="0" wire:model="credit_amount" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                        @error('credit_amount') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                </div>
            @endif
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">
                    @if ($canSupplierDecide) Tedarikçi yanıtı @elseif ($canShip) Kargo bilgisi @else Not / gerekçe @endif
                </label>
                <input type="text" wire:model="actionNote" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($canApprove)
                    <button wire:click="approve" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Onayla</button>
                @endif
                @if ($canShip)
                    <button wire:click="ship" wire:confirm="İade kargoya verilsin mi? Miktar lottan düşülecek." class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kargoya Verildi</button>
                @endif
                @if ($canSupplierDecide)
                    <button wire:click="supplierApprove" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Tedarikçi Onayladı</button>
                    <button wire:click="supplierReject" wire:confirm="Tedarikçi iadeyi reddetti mi? Ürün stoğa geri eklenecek." class="text-[14px] text-status-critical hover:underline">Tedarikçi Reddetti</button>
                @endif
                @if ($canComplete)
                    <button wire:click="complete" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Tamamla</button>
                @endif
                @if ($canReject)
                    <button wire:click="reject" wire:confirm="İade talebi reddedilsin mi? Stok değişmez." class="text-[14px] text-status-critical hover:underline">Reddet</button>
                @endif
            </div>
        </section>
    @endif

    @if ($movements->isNotEmpty())
        <section class="mt-10">
            <h2 class="text-[15px] font-medium text-ink mb-3">Stok Hareketleri</h2>
            <div class="border border-line rounded-lg bg-surface overflow-x-auto">
                <table class="w-full text-[14px]">
                    <tbody class="divide-y divide-line">
                        @foreach ($movements as $movement)
                            <tr>
                                <td class="px-5 py-3 text-ink-muted">{{ $movement->created_at->format('d.m.Y H:i') }}</td>
                                <td class="px-5 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">{{ $movement->type->label() }}</span></td>
                                <td class="px-5 py-3 text-[13px] text-ink-muted">{{ $movement->reason }}</td>
                                <td class="px-5 py-3 text-right tabular-nums {{ (float) $movement->quantity < 0 ? 'text-status-critical' : 'text-status-good' }}">{{ (float) $movement->quantity > 0 ? '+' : '' }}{{ Number::format((float) $movement->quantity, precision: 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="mt-10">
        <h2 class="text-[15px] font-medium text-ink mb-3">İade Geçmişi</h2>
        <ol class="border-l border-line ml-1 space-y-3">
            @foreach ($return->events->sortBy('id') as $event)
                <li class="pl-4 relative">
                    <span class="absolute -left-[5px] top-1.5 w-2 h-2 rounded-full bg-brand-500"></span>
                    <div class="text-[13px]"><span class="font-medium">{{ $event->status->label() }}</span> · {{ $event->actor?->name ?? 'Sistem' }}</div>
                    <div class="text-[12px] text-ink-muted">{{ $event->created_at->format('d.m.Y H:i') }}@if ($event->note) — {{ $event->note }}@endif</div>
                </li>
            @endforeach
        </ol>
    </section>
</div>
