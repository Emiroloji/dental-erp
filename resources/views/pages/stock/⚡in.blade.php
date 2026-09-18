<?php

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public bool $showForm = false;

    public string $product_id = '';

    public string $warehouse_id = '';

    public string $quantity = '';

    public string $unit_cost = '0';

    public string $lot_no = '';

    public string $expiry_date = '';

    public string $reason = '';

    public function openForm(): void
    {
        Gate::authorize('stock_movement.create');

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['product_id', 'warehouse_id', 'quantity', 'lot_no', 'expiry_date', 'reason']);
        $this->unit_cost = '0';
        $this->resetValidation();
    }

    public function save(StockMovementService $service): void
    {
        Gate::authorize('stock_movement.create');

        $validated = $this->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lot_no' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $product = Product::findOrFail($validated['product_id']);

            // Warehouse has no BelongsToOrganization scope of its own; re-fetching it
            // through its scoped Branch relation is what actually enforces tenant isolation.
            $warehouse = Warehouse::whereHas('branch')->inBranches($this->branchIds())->findOrFail($validated['warehouse_id']);
        } catch (ModelNotFoundException) {
            // Livewire's test harness doesn't convert ModelNotFoundException into a 404
            // response the way a real HTTP request does, so we convert it explicitly —
            // this also keeps error handling consistent across all three write screens.
            abort(404);
        }

        try {
            $service->in(
                $product,
                $warehouse,
                (float) $validated['quantity'],
                [
                    'lot_no' => $validated['lot_no'] ?: null,
                    'expiry_date' => $validated['expiry_date'] ?: null,
                    'unit_cost' => filled($validated['unit_cost']) ? (float) $validated['unit_cost'] : 0,
                ],
                auth()->user(),
                $validated['reason'] ?: null,
            );
        } catch (InactiveLocationException $e) {
            $this->addError('warehouse_id', $e->getMessage());

            return;
        }

        $this->closeForm();
        session()->flash('status', 'Stok girişi kaydedildi.');
    }

    /**
     * @return array<int, int>|null
     */
    private function branchIds(): ?array
    {
        return auth()->user()->accessibleBranchIds(Module::StockMovement);
    }

    public function with(): array
    {
        $movements = StockMovement::query()
            ->whereHas('lot.product')
            ->inBranches($this->branchIds())
            ->where('type', StockMovementType::In->value)
            ->with(['lot.product', 'warehouse', 'actor'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(10);

        return [
            'movements' => $movements,
            'products' => Product::with('supplier')->where('status', 'active')->orderBy('name')->get(),
            'warehouses' => Warehouse::operational()->inBranches($this->branchIds())->with('branch')->orderBy('name')->get(),
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Stok Girişleri</h1>
            <p class="text-[14px] text-ink-muted mt-1">Depolara yapılan stok girişlerini kaydet ve son girişleri görüntüle.</p>
        </div>
        @can('stock_movement.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Giriş
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
                    <th class="px-5 py-3 font-medium">Açıklama</th>
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
                        <td class="px-5 py-3 text-right text-status-good tabular-nums">+{{ Number::format((float) $movement->quantity, precision: 2) }}</td>
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

    <x-modal :show="$showForm" title="Yeni Stok Girişi" on-close="closeForm">
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
                    <label class="block text-[13px] text-ink-muted mb-1.5">Depo</label>
                    <select wire:model="warehouse_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Seçiniz</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Miktar</label>
                    <input type="number" step="0.01" wire:model="quantity" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('quantity') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Alış Fiyatı (Birim)</label>
                    <input type="number" step="0.01" wire:model="unit_cost" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Lot No</label>
                    <input type="text" wire:model="lot_no" class="w-full border border-line rounded-md px-3 py-2 text-[14px] font-mono focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Son Kullanma Tarihi</label>
                    <input type="date" wire:model="expiry_date" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                </div>
            </div>

            @if ($product_id && ($selectedSupplier = $products->firstWhere('id', (int) $product_id)?->supplier))
                <p class="text-[13px] text-ink-muted">Tedarikçi: <span class="text-ink">{{ $selectedSupplier->name }}</span></p>
            @endif

            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Açıklama / Fatura-İrsaliye No</label>
                <input type="text" wire:model="reason" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    Girişi Kaydet
                </button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">
                    Vazgeç
                </button>
            </div>
        </form>
    </x-modal>
</div>
