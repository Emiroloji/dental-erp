<?php

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Transfer\Exceptions\TransferException;
use App\Domain\Transfer\Models\TransferRequest;
use App\Domain\Transfer\Services\TransferService;
use App\Domain\Transfer\Support\TransferPermissions;
use App\Domain\Transfer\Support\TransferStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public bool $showForm = false;

    public string $product_id = '';

    public string $from_warehouse_id = '';

    public string $to_warehouse_id = '';

    public string $quantity = '';

    public string $reason = '';

    public ?int $noteTransferId = null;

    public string $noteAction = '';

    public string $note = '';

    public ?int $detailId = null;

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openForm(): void
    {
        Gate::authorize('transfer.create');

        $this->resetForm();
        $this->to_warehouse_id = (string) ($this->destinationWarehouses()->first()?->id ?? '');
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function save(TransferService $transfers): void
    {
        Gate::authorize('transfer.create');

        $validated = $this->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('organization_id', auth()->user()->organization_id)->where('status', 'active')],
            'to_warehouse_id' => ['required', Rule::in($this->destinationWarehouses()->modelKeys())],
            'from_warehouse_id' => ['required', 'different:to_warehouse_id', Rule::in($this->sourceWarehouses()->modelKeys())],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'to_warehouse_id.in' => 'Yalnızca kendi kapsamındaki bir depo için talep açabilirsin.',
            'from_warehouse_id.different' => 'Kaynak ve hedef depo aynı olamaz.',
        ]);

        $this->attempt(fn () => $transfers->request(
            Product::findOrFail($validated['product_id']),
            Warehouse::findOrFail($validated['from_warehouse_id']),
            Warehouse::findOrFail($validated['to_warehouse_id']),
            (float) $validated['quantity'],
            $validated['reason'] ?: null,
            auth()->user(),
        ), 'Transfer talebi oluşturuldu.');

        $this->closeForm();
    }

    public function approve(int $transferId, TransferService $transfers): void
    {
        $this->attempt(fn () => $transfers->approve($this->findTransfer($transferId), auth()->user()), 'Talep onaylandı.');
    }

    public function prepare(int $transferId, TransferService $transfers): void
    {
        $this->attempt(fn () => $transfers->prepare($this->findTransfer($transferId), auth()->user()), 'Transfer hazırlanıyor.');
    }

    public function ship(int $transferId, TransferService $transfers): void
    {
        $this->attempt(fn () => $transfers->ship($this->findTransfer($transferId), auth()->user()), 'Transfer gönderildi; stok kaynak depodan düşüldü.');
    }

    public function receive(int $transferId, TransferService $transfers): void
    {
        $this->attempt(fn () => $transfers->receive($this->findTransfer($transferId), auth()->user()), 'Transfer teslim alındı; stok hedef depoya eklendi.');
    }

    public function reject(int $transferId, TransferService $transfers): void
    {
        $this->attempt(fn () => $transfers->reject($this->findTransfer($transferId), auth()->user(), $this->note ?: null), 'Talep reddedildi.');
    }

    public function cancel(int $transferId, TransferService $transfers): void
    {
        $this->attempt(fn () => $transfers->cancel($this->findTransfer($transferId), auth()->user(), $this->note ?: null), 'Transfer iptal edildi.');
    }

    /**
     * Red ve iptal bir gerekçe ile yapılır; önce not penceresi açılır.
     */
    public function askNote(int $transferId, string $action): void
    {
        $this->findTransfer($transferId);

        $this->noteTransferId = $transferId;
        $this->noteAction = in_array($action, ['reject', 'cancel'], true) ? $action : '';
        $this->note = '';
    }

    public function confirmNote(TransferService $transfers): void
    {
        $this->validate(['note' => ['nullable', 'string', 'max:255']]);

        match ($this->noteAction) {
            'reject' => $this->reject((int) $this->noteTransferId, $transfers),
            'cancel' => $this->cancel((int) $this->noteTransferId, $transfers),
            default => null,
        };

        $this->closeNote();
    }

    public function closeNote(): void
    {
        $this->reset(['noteTransferId', 'noteAction', 'note']);
    }

    public function showDetail(int $transferId): void
    {
        $this->detailId = $this->findTransfer($transferId)->id;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    /**
     * Transfer kuralı ihlalleri kullanıcıya mesaj olarak gösterilir; yetki
     * hataları (AuthorizationException) Livewire tarafından 403'e çevrilir.
     */
    private function attempt(Closure $action, string $success): void
    {
        try {
            $action();
        } catch (TransferException|InsufficientStockException|InactiveLocationException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', $success);
    }

    private function findTransfer(int $transferId): TransferRequest
    {
        try {
            return $this->visibleTransfers()->findOrFail($transferId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function visibleTransfers()
    {
        return TransferRequest::query()
            ->inBranches(auth()->user()->accessibleBranchIds(Module::Transfer))
            ->with(['product', 'fromWarehouse.branch', 'toWarehouse.branch', 'requester']);
    }

    /**
     * Talep yalnızca kullanıcının Transfer kapsamındaki işleme açık depolara açılabilir.
     */
    private function destinationWarehouses()
    {
        return Warehouse::operational()
            ->inBranches(auth()->user()->accessibleBranchIds(Module::Transfer))
            ->with('branch')
            ->orderBy('name')
            ->get();
    }

    /**
     * Kaynak, organizasyonun işleme açık herhangi bir deposu olabilir — başka
     * şubeden ürün istemek transferin amacıdır.
     */
    private function sourceWarehouses()
    {
        return Warehouse::operational()->with('branch')->orderBy('name')->get();
    }

    private function resetForm(): void
    {
        $this->reset(['product_id', 'from_warehouse_id', 'to_warehouse_id', 'quantity', 'reason']);
        $this->resetValidation();
    }

    public function with(TransferPermissions $permissions): array
    {
        $sourceAvailable = null;
        if ($this->showForm && filled($this->product_id) && filled($this->from_warehouse_id)) {
            $sourceAvailable = (float) StockLot::where('product_id', $this->product_id)
                ->where('warehouse_id', $this->from_warehouse_id)
                ->whereHas('product')
                ->sum('quantity');
        }

        $detail = $this->detailId
            ? $this->visibleTransfers()->with(['events.actor', 'movements.lot'])->find($this->detailId)
            : null;

        return [
            'transfers' => $this->visibleTransfers()
                ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
                ->latest()
                ->orderByDesc('id')
                ->paginate(15),
            'statuses' => TransferStatus::cases(),
            'permissions' => $permissions,
            'products' => $this->showForm ? Product::where('status', 'active')->orderBy('name')->get() : collect(),
            'destinations' => $this->showForm ? $this->destinationWarehouses() : collect(),
            'sources' => $this->showForm ? $this->sourceWarehouses() : collect(),
            'sourceAvailable' => $sourceAvailable,
            'detail' => $detail,
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Transferler</h1>
            <p class="text-[14px] text-ink-muted mt-1">Şubeler ve depolar arası ürün talepleri. Stok yalnızca gönderimde düşer, teslim alımda eklenir.</p>
        </div>
        @can('transfer.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Talep
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md bg-status-critical-bg border border-status-critical/20 text-status-critical text-[13px] px-4 py-3">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Durumlar</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">No</th>
                    <th class="px-5 py-3 font-medium">Ürün</th>
                    <th class="px-5 py-3 font-medium text-right">Miktar</th>
                    <th class="px-5 py-3 font-medium">Kaynak → Hedef</th>
                    <th class="px-5 py-3 font-medium">Talep Eden</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                    <th class="px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($transfers as $transfer)
                    @php $user = auth()->user(); $status = $transfer->status; @endphp
                    <tr wire:key="transfer-{{ $transfer->id }}">
                        <td class="px-5 py-3">
                            <button wire:click="showDetail({{ $transfer->id }})" class="font-mono text-[13px] text-brand-600 hover:underline">#{{ $transfer->id }}</button>
                            <div class="text-[12px] text-ink-muted">{{ $transfer->created_at->format('d.m.Y H:i') }}</div>
                        </td>
                        <td class="px-5 py-3">{{ $transfer->product->name }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ Number::format((float) $transfer->quantity, precision: 2) }}</td>
                        <td class="px-5 py-3 text-[13px]">
                            <div>{{ $transfer->fromWarehouse->branch->name }} · {{ $transfer->fromWarehouse->name }}</div>
                            <div class="text-ink-muted">→ {{ $transfer->toWarehouse->branch->name }} · {{ $transfer->toWarehouse->name }}</div>
                        </td>
                        <td class="px-5 py-3 text-ink-muted">{{ $transfer->requester?->name ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $status->badgeClasses() }}">{{ $status->label() }}</span>
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap space-x-3 text-[13px]">
                            @if ($status->canTransitionTo(TransferStatus::Approved) && $permissions->can($user, 'approve', $transfer))
                                <button wire:click="approve({{ $transfer->id }})" class="text-brand-600 hover:underline">Onayla</button>
                            @endif
                            @if ($status->canTransitionTo(TransferStatus::Rejected) && $permissions->can($user, 'reject', $transfer))
                                <button wire:click="askNote({{ $transfer->id }}, 'reject')" class="text-status-critical hover:underline">Reddet</button>
                            @endif
                            @if ($status->canTransitionTo(TransferStatus::Preparing) && $permissions->can($user, 'prepare', $transfer))
                                <button wire:click="prepare({{ $transfer->id }})" class="text-brand-600 hover:underline">Hazırla</button>
                            @endif
                            @if ($status->canTransitionTo(TransferStatus::Shipped) && $permissions->can($user, 'ship', $transfer))
                                <button wire:click="ship({{ $transfer->id }})" wire:confirm="Gönderildi olarak işaretlenince stok kaynak depodan düşülür. Devam edilsin mi?" class="text-brand-600 hover:underline">Gönder</button>
                            @endif
                            @if ($status->canTransitionTo(TransferStatus::Received) && $permissions->can($user, 'receive', $transfer))
                                <button wire:click="receive({{ $transfer->id }})" wire:confirm="Ürünleri teslim aldığını onaylıyor musun? Stok hedef depoya eklenecek." class="text-brand-600 hover:underline">Teslim Al</button>
                            @endif
                            @if ($status->canTransitionTo(TransferStatus::Cancelled) && $permissions->can($user, 'cancel', $transfer))
                                <button wire:click="askNote({{ $transfer->id }}, 'cancel')" class="text-ink-muted hover:text-status-critical hover:underline">İptal</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-ink-muted text-[13px]">Henüz transfer yok.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($transfers->hasPages())
            <div class="px-5 py-3 border-t border-line">
                {{ $transfers->links() }}
            </div>
        @endif
    </section>

    <x-modal :show="$showForm" title="Yeni Transfer Talebi" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Ürün</label>
                <select wire:model.live="product_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    <option value="">Ürün seçin</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                    @endforeach
                </select>
                @error('product_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Hedef Depo (ihtiyacı olan)</label>
                    <select wire:model.live="to_warehouse_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Depo seçin</option>
                        @foreach ($destinations as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    @error('to_warehouse_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Kaynak Depo (stoğu veren)</label>
                    <select wire:model.live="from_warehouse_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Depo seçin</option>
                        @foreach ($sources as $warehouse)
                            @if ((string) $warehouse->id !== $to_warehouse_id)
                                <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    @if ($sourceAvailable !== null)
                        <span class="text-[12px] text-ink-muted">Kaynakta mevcut: {{ Number::format($sourceAvailable, precision: 2) }}</span>
                    @endif
                    @error('from_warehouse_id') <span class="text-status-critical text-[12px] block">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Miktar</label>
                    <input type="number" step="0.01" wire:model="quantity" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('quantity') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[13px] text-ink-muted mb-1.5">Neden</label>
                    <input type="text" wire:model="reason" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('reason') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            <p class="text-[12px] text-ink-muted">Talep açıldığında stok değişmez. Kaynak taraf onaylayıp gönderdiğinde düşer, sen teslim aldığında deponuza eklenir.</p>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Talep Oluştur</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>

    <x-modal :show="$noteTransferId !== null" :title="$noteAction === 'reject' ? 'Talebi Reddet' : 'Transferi İptal Et'" on-close="closeNote">
        <form wire:submit="confirmNote" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Gerekçe</label>
                <input type="text" wire:model="note" autofocus class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('note') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            @if ($noteAction === 'cancel')
                <p class="text-[12px] text-ink-muted">Transfer gönderildiyse, düşülen miktar kaynak depoya geri eklenir.</p>
            @endif
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-status-critical text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:opacity-90 transition-opacity">{{ $noteAction === 'reject' ? 'Reddet' : 'İptal Et' }}</button>
                <button type="button" wire:click="closeNote" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>

    <x-modal :show="$detail !== null" :title="$detail ? 'Transfer #'.$detail->id : ''" on-close="closeDetail">
        @if ($detail)
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-[13px] mb-6">
                <dt class="text-ink-muted">Ürün</dt><dd>{{ $detail->product->name }}</dd>
                <dt class="text-ink-muted">Miktar</dt><dd class="tabular-nums">{{ Number::format((float) $detail->quantity, precision: 2) }} {{ $detail->product->base_unit }}</dd>
                <dt class="text-ink-muted">Kaynak</dt><dd>{{ $detail->fromWarehouse->branch->name }} · {{ $detail->fromWarehouse->name }}</dd>
                <dt class="text-ink-muted">Hedef</dt><dd>{{ $detail->toWarehouse->branch->name }} · {{ $detail->toWarehouse->name }}</dd>
                <dt class="text-ink-muted">Neden</dt><dd>{{ $detail->reason ?? '—' }}</dd>
            </dl>

            <h3 class="text-[13px] font-medium text-ink mb-2">Durum Geçmişi</h3>
            <ol class="border-l border-line ml-1 space-y-3 mb-6">
                @foreach ($detail->events->sortBy('id') as $event)
                    <li class="pl-4 relative">
                        <span class="absolute -left-[5px] top-1.5 w-2 h-2 rounded-full bg-brand-500"></span>
                        <div class="text-[13px]"><span class="font-medium">{{ $event->status->label() }}</span> · {{ $event->actor?->name ?? 'Sistem' }}</div>
                        <div class="text-[12px] text-ink-muted">{{ $event->created_at->format('d.m.Y H:i') }}@if ($event->note) — {{ $event->note }}@endif</div>
                    </li>
                @endforeach
            </ol>

            @if ($detail->movements->isNotEmpty())
                <h3 class="text-[13px] font-medium text-ink mb-2">Stok Hareketleri</h3>
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                            <th class="py-2 font-medium">Tip</th>
                            <th class="py-2 font-medium">Lot</th>
                            <th class="py-2 font-medium">SKT</th>
                            <th class="py-2 font-medium text-right">Miktar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($detail->movements->sortBy('id') as $movement)
                            <tr>
                                <td class="py-2">{{ $movement->type->label() }}</td>
                                <td class="py-2 font-mono">{{ $movement->lot->lot_no ?? '—' }}</td>
                                <td class="py-2">{{ $movement->lot->expiry_date?->format('d.m.Y') ?? '—' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ Number::format((float) $movement->quantity, precision: 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </x-modal>
</div>
