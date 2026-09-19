<?php

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Services\ReturnService;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Returns\Support\ReturnStatus;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Support\SerialStatus;
use App\Domain\Stock\Support\StockMovementType;
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

    public string $warehouse_id = '';

    public string $product_id = '';

    public string $lot_id = '';

    public string $quantity = '';

    public string $reason = '';

    public string $reason_note = '';

    public string $supplier_id = '';

    public string $purchase_order_id = '';

    public string $invoice_number = '';

    /** Seri takipli üründe iade edilen birimler (Aşama 26); miktar seri sayısıdır. */
    public array $selectedSerials = [];

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openForm(): void
    {
        Gate::authorize('stock_movement.create');

        $this->reset(['warehouse_id', 'product_id', 'lot_id', 'quantity', 'reason', 'reason_note', 'supplier_id', 'purchase_order_id', 'invoice_number', 'selectedSerials']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function updatedWarehouseId(): void
    {
        $this->reset(['product_id', 'lot_id']);
    }

    public function updatedProductId(): void
    {
        $this->lot_id = '';

        $product = filled($this->product_id) ? Product::find($this->product_id) : null;
        $this->supplier_id = (string) ($product?->supplier_id ?? '');
        $this->reset(['purchase_order_id', 'invoice_number']);
    }

    /**
     * Lot bir satın alma tesliminden geldiyse tedarikçi, sipariş ve fatura
     * numarası o teslimden önerilir (proje.md Bölüm 9: stok girişi siparişe
     * ve tedarikçiye bağlı kalır).
     */
    public function updatedSelectedSerials(): void
    {
        $this->quantity = (string) count($this->selectedSerials);
    }

    public function updatedLotId(): void
    {
        $this->selectedSerials = [];

        $receipt = $this->receiptOf($this->lot_id);

        if ($receipt) {
            $this->supplier_id = (string) $receipt->order->supplier_id;
            $this->purchase_order_id = (string) $receipt->purchase_order_id;
            $this->invoice_number = (string) $receipt->invoice_number;
        }
    }

    public function updatedSupplierId(): void
    {
        $this->purchase_order_id = '';
    }

    public function save(ReturnService $returns): void
    {
        Gate::authorize('stock_movement.create');

        $validated = $this->validate([
            'warehouse_id' => ['required', Rule::in($this->warehouses()->modelKeys())],
            'product_id' => ['required'],
            'lot_id' => ['required', Rule::in($this->lots()->modelKeys())],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'reason_note' => ['nullable', 'string', 'max:255', Rule::requiredIf($this->reason === ReturnReason::Other->value)],
            'supplier_id' => ['required', Rule::in(Supplier::pluck('id')->all())],
            'purchase_order_id' => ['nullable', Rule::in($this->orders()->modelKeys())],
            'invoice_number' => ['nullable', 'string', 'max:255'],
        ], [
            'warehouse_id.in' => 'Yalnızca kendi kapsamındaki işleme açık bir depodan iade açabilirsin.',
            'lot_id.in' => 'Seçilen lot bu depo ve ürünle eşleşmiyor.',
            'reason_note.required' => '"Diğer" nedeni için açıklama yazın.',
            'purchase_order_id.in' => 'Seçilen sipariş bu tedarikçiden bu ürünün teslim alındığı bir sipariş değil.',
        ]);

        try {
            $return = $returns->request(
                StockLot::findOrFail($validated['lot_id']),
                (float) $validated['quantity'],
                ReturnReason::from($validated['reason']),
                Supplier::findOrFail($validated['supplier_id']),
                auth()->user(),
                [
                    'reason_note' => $validated['reason_note'],
                    'purchase_order_id' => $validated['purchase_order_id'] ?: null,
                    'invoice_number' => $validated['invoice_number'],
                    'serials' => array_values(array_map('strval', $this->selectedSerials)),
                ],
            );
        } catch (ReturnException $e) {
            $this->addError('quantity', $e->getMessage());

            return;
        }

        $this->redirect(route('returns.show', $return));
    }

    private function receiptOf(string $lotId): ?PurchaseReceipt
    {
        if (blank($lotId)) {
            return null;
        }

        $movement = StockMovement::where('lot_id', $lotId)
            ->where('type', StockMovementType::In->value)
            ->where('related_entity_type', (new PurchaseReceipt)->getMorphClass())
            ->orderBy('id')
            ->first();

        return $movement ? PurchaseReceipt::with('order')->find($movement->related_entity_id) : null;
    }

    private function branchIds(): ?array
    {
        return auth()->user()->accessibleBranchIds(Module::StockMovement);
    }

    private function warehouses()
    {
        return Warehouse::operational()->inBranches($this->branchIds())->with('branch')->orderBy('name')->get();
    }

    private function lots()
    {
        if (blank($this->warehouse_id) || blank($this->product_id)) {
            return collect();
        }

        return StockLot::whereHas('product')
            ->inBranches($this->branchIds())
            ->where('warehouse_id', $this->warehouse_id)
            ->where('product_id', $this->product_id)
            ->where('quantity', '>', 0)
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->get();
    }

    /**
     * Seçilen tedarikçiden bu ürünün teslim alındığı siparişler.
     */
    private function orders()
    {
        if (blank($this->supplier_id) || blank($this->product_id)) {
            return collect();
        }

        return PurchaseOrder::where('supplier_id', $this->supplier_id)
            ->whereHas('lines', fn ($query) => $query->where('product_id', $this->product_id)->where('received_quantity', '>', 0))
            ->orderByDesc('id')
            ->get();
    }

    public function with(): array
    {
        $warehouses = $this->showForm ? $this->warehouses() : collect();

        return [
            'returns' => SupplierReturn::query()
                ->inBranches($this->branchIds())
                ->with(['warehouse.branch', 'product', 'lot', 'supplier'])
                ->when($this->statusFilter === 'open', fn ($query) => $query->whereIn('status', ReturnStatus::openStatuses()))
                ->when($this->statusFilter && $this->statusFilter !== 'open', fn ($query) => $query->where('status', $this->statusFilter))
                ->latest()
                ->orderByDesc('id')
                ->paginate(15),
            'statuses' => ReturnStatus::cases(),
            'warehouses' => $warehouses,
            'products' => $this->showForm && filled($this->warehouse_id)
                ? Product::whereIn('id', StockLot::inBranches($this->branchIds())->where('warehouse_id', $this->warehouse_id)->where('quantity', '>', 0)->select('product_id'))->orderBy('name')->get()
                : collect(),
            'lots' => $this->showForm ? $this->lots() : collect(),
            'lotSerials' => $this->showForm && filled($this->lot_id)
                ? StockSerial::where('lot_id', $this->lot_id)->whereIn('lot_id', $this->lots()->modelKeys())->where('status', SerialStatus::InStock->value)->orderBy('serial_no')->limit(200)->get()
                : collect(),
            'reasons' => ReturnReason::cases(),
            'suppliers' => $this->showForm ? Supplier::where(fn ($query) => $query->where('status', 'active')->orWhere('id', $this->supplier_id ?: null))->orderBy('name')->get() : collect(),
            'orders' => $this->showForm ? $this->orders() : collect(),
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">İadeler</h1>
            <p class="text-[14px] text-ink-muted mt-1">Hatalı, hasarlı veya yanlış gelen ürünlerin tedarikçiye iadesini talepten kapanışa kadar takip et.</p>
        </div>
        @can('stock_movement.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni İade
            </button>
        @endcan
    </div>

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Durumlar</option>
            <option value="open">Açık İadeler (sonuçlanmamış)</option>
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
                    <th class="px-5 py-3 font-medium">Ürün / Lot</th>
                    <th class="px-5 py-3 font-medium">Depo</th>
                    <th class="px-5 py-3 font-medium">Tedarikçi</th>
                    <th class="px-5 py-3 font-medium">Neden</th>
                    <th class="px-5 py-3 font-medium text-right">Miktar</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($returns as $return)
                    <tr wire:key="return-{{ $return->id }}">
                        <td class="px-5 py-3">
                            <a href="{{ route('returns.show', $return) }}" class="font-mono text-[13px] text-brand-600 hover:underline">{{ $return->number() }}</a>
                            <div class="text-[12px] text-ink-muted">{{ $return->created_at->format('d.m.Y H:i') }}</div>
                        </td>
                        <td class="px-5 py-3">
                            {{ $return->product->name }}
                            <div class="text-[12px] text-ink-muted font-mono">{{ $return->lot->lot_no ?? '—' }}</div>
                        </td>
                        <td class="px-5 py-3 text-[13px]">{{ $return->warehouse->branch->name }} · {{ $return->warehouse->name }}</td>
                        <td class="px-5 py-3 text-[13px]">{{ $return->supplier->name }}</td>
                        <td class="px-5 py-3 text-[13px] text-ink-muted">{{ $return->reason->label() }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ Number::format((float) $return->quantity, precision: 2) }}</td>
                        <td class="px-5 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $return->status->badgeClasses() }}">{{ $return->status->label() }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-ink-muted text-[13px]">Henüz iade kaydı yok.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($returns->hasPages())
            <div class="px-5 py-3 border-t border-line">{{ $returns->links() }}</div>
        @endif
    </section>

    <x-modal :show="$showForm" title="Yeni İade Talebi" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Depo</label>
                <select wire:model.live="warehouse_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    <option value="">Depo seçin</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
                    @endforeach
                </select>
                @error('warehouse_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Ürün</label>
                    <select wire:model.live="product_id" @disabled(blank($warehouse_id)) class="w-full border border-line rounded-md px-3 py-2 text-[14px] disabled:bg-canvas focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Ürün seçin</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Lot</label>
                    <select wire:model.live="lot_id" @disabled(blank($product_id)) class="w-full border border-line rounded-md px-3 py-2 text-[14px] disabled:bg-canvas focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Lot seçin</option>
                        @foreach ($lots as $lot)
                            <option value="{{ $lot->id }}">{{ $lot->lot_no ?? 'Lot #'.$lot->id }} — SKT {{ $lot->expiry_date?->format('d.m.Y') ?? '—' }} — {{ Number::format((float) $lot->quantity, precision: 2) }} mevcut</option>
                        @endforeach
                    </select>
                    @error('lot_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            @if ($lotSerials->isNotEmpty())
                <div class="rounded-md border border-line bg-canvas px-3 py-2.5 text-[13px]">
                    <p class="text-ink-muted mb-2">İade edilecek birimlerin seri numaraları — {{ count($selectedSerials) }} seçili</p>
                    <div class="max-h-40 overflow-y-auto grid grid-cols-2 sm:grid-cols-3 gap-1">
                        @foreach ($lotSerials as $serial)
                            <label class="flex items-center gap-1.5 font-mono text-[12px]">
                                <input type="checkbox" wire:model.live="selectedSerials" value="{{ $serial->serial_no }}" class="accent-brand-500">
                                {{ $serial->serial_no }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Miktar</label>
                    <input type="number" step="0.01" min="0" wire:model="quantity" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('quantity') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">İade Nedeni</label>
                    <select wire:model.live="reason" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Neden seçin</option>
                        @foreach ($reasons as $reasonOption)
                            <option value="{{ $reasonOption->value }}">{{ $reasonOption->label() }}</option>
                        @endforeach
                    </select>
                    @error('reason') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Açıklama @if ($reason === 'other')<span class="text-status-critical">*</span>@endif</label>
                <input type="text" wire:model="reason_note" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('reason_note') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Tedarikçi</label>
                    <select wire:model.live="supplier_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Tedarikçi seçin</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">İlgili Sipariş</label>
                    <select wire:model="purchase_order_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">— Yok —</option>
                        @foreach ($orders as $order)
                            <option value="{{ $order->id }}">{{ $order->number() }} · {{ $order->created_at->format('d.m.Y') }}</option>
                        @endforeach
                    </select>
                    @error('purchase_order_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Fatura No</label>
                <input type="text" wire:model="invoice_number" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            </div>
            <p class="text-[12px] text-ink-muted">Talep Admin onayından sonra kargoya verilir; stok ancak "Kargoya Verildi" anında lottan düşer.</p>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Talep Oluştur</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
