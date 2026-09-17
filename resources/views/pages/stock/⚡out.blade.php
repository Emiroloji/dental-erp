<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Stock\Support\StockOutReason;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public bool $showForm = false;

    public string $product_id = '';

    public string $warehouse_id = '';

    public string $lot_id = '';

    public string $quantity = '';

    public string $reasonCategory = '';

    public string $reasonNote = '';

    public function openForm(): void
    {
        Gate::authorize('stock_movement.create');

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['product_id', 'warehouse_id', 'lot_id', 'quantity', 'reasonCategory', 'reasonNote']);
        $this->resetValidation();
    }

    public function updatedProductId(): void
    {
        $this->lot_id = '';
    }

    public function updatedWarehouseId(): void
    {
        $this->lot_id = '';
    }

    public function save(StockMovementService $service): void
    {
        Gate::authorize('stock_movement.create');

        $validated = $this->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'lot_id' => ['nullable', 'exists:stock_lots,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'reasonCategory' => ['required', Rule::in(array_column(StockOutReason::cases(), 'value'))],
            'reasonNote' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $product = Product::findOrFail($validated['product_id']);

            // Warehouse has no BelongsToOrganization scope of its own; re-fetching it
            // through its scoped Branch relation is what actually enforces tenant isolation.
            $warehouse = Warehouse::whereHas('branch')->findOrFail($validated['warehouse_id']);
        } catch (ModelNotFoundException) {
            // Livewire's test harness doesn't convert ModelNotFoundException into a 404
            // response the way a real HTTP request does, so we convert it explicitly —
            // this also keeps error handling consistent across all three write screens.
            abort(404);
        }

        $lot = null;
        if (filled($validated['lot_id'])) {
            try {
                $lot = StockLot::whereHas('product')
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->findOrFail($validated['lot_id']);
            } catch (ModelNotFoundException) {
                $this->addError('lot_id', 'Seçilen lot ürünle eşleşmiyor.');

                return;
            }
        }

        $reasonLabel = StockOutReason::from($validated['reasonCategory'])->label();
        $reason = filled($validated['reasonNote']) ? "{$reasonLabel}: {$validated['reasonNote']}" : $reasonLabel;

        try {
            $service->out($product, $warehouse, (float) $validated['quantity'], $lot, auth()->user(), $reason);
        } catch (InsufficientStockException $e) {
            $this->addError('quantity', $e->getMessage());

            return;
        }

        $this->closeForm();
        session()->flash('status', 'Stok çıkışı kaydedildi.');
    }

    public function with(): array
    {
        $movements = StockMovement::query()
            ->whereHas('lot.product')
            ->where('type', StockMovementType::Out->value)
            ->with(['lot.product', 'warehouse', 'actor'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(10);

        $availableLots = collect();
        if (filled($this->product_id) && filled($this->warehouse_id)) {
            $availableLots = StockLot::whereHas('product')
                ->where('product_id', $this->product_id)
                ->where('warehouse_id', $this->warehouse_id)
                ->where('quantity', '>', 0)
                ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
                ->get();
        }

        return [
            'movements' => $movements,
            'products' => Product::where('status', 'active')->orderBy('name')->get(),
            'warehouses' => Warehouse::whereHas('branch')->with('branch')->where('status', 'active')->orderBy('name')->get(),
            'availableLots' => $availableLots,
            'reasons' => StockOutReason::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Stok Çıkışları</h1>
            <p class="text-[14px] text-ink-muted mt-1">Depolardan yapılan stok çıkışlarını kaydet ve son çıkışları görüntüle.</p>
        </div>
        @can('stock_movement.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Çıkış
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">
            {{ session('status') }}
        </div>
    @endif

    <section class="border border-line rounded-lg bg-surface overflow-hidden">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Tarih</th>
                    <th class="px-5 py-3 font-medium">Ürün</th>
                    <th class="px-5 py-3 font-medium">Depo</th>
                    <th class="px-5 py-3 font-medium">Lot</th>
                    <th class="px-5 py-3 font-medium">Personel</th>
                    <th class="px-5 py-3 font-medium">Neden</th>
                    <th class="px-5 py-3 font-medium text-right">Miktar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($movements as $movement)
                    <tr wire:key="movement-{{ $movement->id }}">
                        <td class="px-5 py-3 text-ink-muted">{{ $movement->created_at->format('d.m.Y H:i') }}</td>
                        <td class="px-5 py-3">{{ $movement->lot->product->name }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $movement->warehouse->name }}</td>
                        <td class="px-5 py-3 font-mono text-[13px] text-ink-muted">{{ $movement->lot->lot_no ?? '—' }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $movement->actor?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $movement->reason ?? '—' }}</td>
                        <td class="px-5 py-3 text-right text-status-critical tabular-nums">{{ number_format((float) $movement->quantity, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-ink-muted text-[13px]">Kayıt bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($movements->hasPages())
            <div class="px-5 py-3 border-t border-line">
                {{ $movements->links() }}
            </div>
        @endif
    </section>

    <x-modal :show="$showForm" title="Yeni Stok Çıkışı" on-close="closeForm">
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Ürün</label>
                    <select wire:model.live="product_id" autofocus class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Seçiniz</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Kaynak Depo</label>
                    <select wire:model.live="warehouse_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Seçiniz</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Lot (opsiyonel)</label>
                    <select wire:model="lot_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Otomatik (FEFO)</option>
                        @foreach ($availableLots as $lot)
                            <option value="{{ $lot->id }}">{{ $lot->lot_no ?? "Lot #{$lot->id}" }} — {{ number_format((float) $lot->quantity, 2) }} mevcut</option>
                        @endforeach
                    </select>
                    @error('lot_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Miktar</label>
                    <input type="number" step="0.01" wire:model="quantity" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('quantity') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Çıkış Nedeni</label>
                    <select wire:model="reasonCategory" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Seçiniz</option>
                        @foreach ($reasons as $reasonOption)
                            <option value="{{ $reasonOption->value }}">{{ $reasonOption->label() }}</option>
                        @endforeach
                    </select>
                    @error('reasonCategory') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Açıklama</label>
                    <input type="text" wire:model="reasonNote" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    Çıkışı Kaydet
                </button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">
                    Vazgeç
                </button>
            </div>
        </form>
    </x-modal>
</div>
