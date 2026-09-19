<?php

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\ControlledProductException;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Exceptions\SerialException;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Support\SerialStatus;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Stock\Support\ExpiredUsageWarning;
use App\Domain\Stock\Support\StockOutReason;
use App\Support\UnitConverter;
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

    /** Girilen miktarın birimi; kayıt ana birime çevrilerek yapılır. */
    public string $unit = '';

    public string $reasonCategory = '';

    public string $reasonNote = '';

    /** Seri takipli üründe çıkan birimler (Aşama 26); miktar seçilen seri sayısıdır. */
    public array $selectedSerials = [];

    public string $serialSearch = '';

    public function openForm(): void
    {
        Gate::authorize('stock_movement.create');

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['product_id', 'warehouse_id', 'lot_id', 'quantity', 'unit', 'reasonCategory', 'reasonNote', 'selectedSerials', 'serialSearch']);
        $this->resetValidation();
    }

    public function updatedProductId(): void
    {
        $this->lot_id = '';
        $this->unit = (string) Product::find($this->product_id)?->base_unit;
        $this->reset(['selectedSerials', 'serialSearch']);
    }

    public function updatedWarehouseId(): void
    {
        $this->lot_id = '';
        $this->reset(['selectedSerials']);
    }

    public function updatedLotId(): void
    {
        $this->reset(['selectedSerials']);
    }

    public function updatedSelectedSerials(): void
    {
        $this->quantity = (string) count($this->selectedSerials);
    }

    public function save(StockMovementService $service, UnitConverter $units): void
    {
        Gate::authorize('stock_movement.create');

        $validated = $this->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'lot_id' => ['nullable', 'exists:stock_lots,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit' => ['nullable', 'string', 'max:50'],
            'reasonCategory' => ['required', Rule::in(array_column(StockOutReason::selectable(), 'value'))],
            'reasonNote' => ['nullable', 'string', 'max:255'],
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

        $unit = filled($validated['unit']) ? $validated['unit'] : $product->base_unit;

        if (! in_array($unit, $product->unitOptions(), true)) {
            $this->addError('unit', 'Bu ürün için tanımlı olmayan birim.');

            return;
        }

        $reasonCode = StockOutReason::from($validated['reasonCategory']);
        $reason = filled($validated['reasonNote']) ? "{$reasonCode->label()}: {$validated['reasonNote']}" : $reasonCode->label();

        // Kayıt her zaman ana birimle yapılır; alternatif birim açıklamada kalır (kurallar.md Bölüm 3).
        if ($unit !== $product->base_unit) {
            $reason .= " ({$validated['quantity']} {$unit})";
        }

        try {
            $movements = $service->out($product, $warehouse, $units->toBaseUnit($product, (float) $validated['quantity'], $unit), $lot, auth()->user(), $reason, $reasonCode, tracking: [
                'note' => $validated['reasonNote'],
                'serials' => $product->tracks_serials ? array_values(array_map('strval', $this->selectedSerials)) : [],
            ]);
        } catch (InsufficientStockException $e) {
            $this->addError('quantity', $e->getMessage());

            return;
        } catch (InactiveLocationException $e) {
            $this->addError('warehouse_id', $e->getMessage());

            return;
        } catch (ControlledProductException $e) {
            $this->addError('reasonNote', $e->getMessage());

            return;
        } catch (SerialException $e) {
            $this->addError('selectedSerials', $e->getMessage());

            return;
        }

        $this->closeForm();
        session()->flash('status', 'Stok çıkışı kaydedildi.');

        if ($warning = ExpiredUsageWarning::for($movements, $reasonCode)) {
            session()->flash('warning', $warning);
        }
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
            ->where('type', StockMovementType::Out->value)
            ->with(['lot.product', 'warehouse', 'actor'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(10);

        $availableLots = collect();
        if (filled($this->product_id) && filled($this->warehouse_id)) {
            $availableLots = StockLot::whereHas('product')
                ->inBranches($this->branchIds())
                ->where('product_id', $this->product_id)
                ->where('warehouse_id', $this->warehouse_id)
                ->where('quantity', '>', 0)
                ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
                ->get();
        }

        $selected = filled($this->product_id) ? Product::find($this->product_id) : null;
        $availableSerials = collect();
        if ($selected?->tracks_serials && filled($this->warehouse_id)) {
            $availableSerials = StockSerial::where('product_id', $selected->id)
                ->where('status', SerialStatus::InStock->value)
                ->whereHas('lot', fn ($query) => $query->where('warehouse_id', $this->warehouse_id)->inBranches($this->branchIds()))
                ->when(filled($this->lot_id), fn ($query) => $query->where('lot_id', $this->lot_id))
                ->when(filled($this->serialSearch), fn ($query) => $query->whereLike('serial_no', "%{$this->serialSearch}%"))
                ->with('lot')
                ->orderBy('serial_no')
                ->limit(200)
                ->get();
        }

        return [
            'movements' => $movements,
            'availableSerials' => $availableSerials,
            'products' => Product::where('status', 'active')->orderBy('name')->get(),
            'warehouses' => Warehouse::operational()->inBranches($this->branchIds())->with('branch')->orderBy('name')->get(),
            'availableLots' => $availableLots,
            'reasons' => StockOutReason::selectable(),
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

    @if (session('warning'))
        <div class="mb-6 rounded-md bg-status-warn-bg border border-status-warn/20 text-status-warn text-[13px] px-4 py-3">
            {{ session('warning') }}
        </div>
    @endif

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
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
                        <td class="px-5 py-3 text-right text-status-critical tabular-nums">{{ Number::format((float) $movement->quantity, precision: 2) }}</td>
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
        @php $selectedProduct = $product_id ? $products->firstWhere('id', (int) $product_id) : null; @endphp
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
                            <option value="{{ $lot->id }}">{{ $lot->lot_no ?? "Lot #{$lot->id}" }} — {{ Number::format((float) $lot->quantity, precision: 2) }} mevcut{{ $lot->isExpired() ? ' · SKT GEÇTİ' : '' }}</option>
                        @endforeach
                    </select>
                    @error('lot_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Miktar</label>
                    <input type="number" step="0.01" wire:model="quantity" @readonly($selectedProduct?->tracks_serials) class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums read-only:bg-canvas focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('quantity') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Birim</label>
                    <select wire:model="unit" @disabled(! $selectedProduct) class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        @if ($selectedProduct)
                            @foreach ($selectedProduct->unitOptions() as $unitOption)
                                <option value="{{ $unitOption }}">{{ $unitOption }}@if ($unitOption !== $selectedProduct->base_unit) (= {{ Number::format((float) collect($selectedProduct->conversion_rules)->firstWhere('unit', $unitOption)['factor'], maxPrecision: 2) }} {{ $selectedProduct->base_unit }})@endif</option>
                            @endforeach
                        @else
                            <option value="">Önce ürün seçin</option>
                        @endif
                    </select>
                    @error('unit') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                @if ($selectedProduct?->tracks_serials)
                    <div class="sm:col-span-2 rounded-md border border-line bg-canvas px-3 py-2.5 text-[13px]">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                            <span class="text-ink-muted">Çıkan birimlerin seri numaraları — {{ count($selectedSerials) }} seçili</span>
                            <input type="text" wire:model.live.debounce.300ms="serialSearch" placeholder="Seri ara / okut" class="border border-line rounded-md px-2 py-1 text-[13px] font-mono bg-surface">
                        </div>
                        @if (blank($warehouse_id))
                            <p class="text-ink-muted">Önce depoyu seçin.</p>
                        @else
                            <div class="max-h-40 overflow-y-auto grid grid-cols-2 sm:grid-cols-3 gap-1">
                                @forelse ($availableSerials as $serial)
                                    <label class="flex items-center gap-1.5 font-mono text-[12px]">
                                        <input type="checkbox" wire:model.live="selectedSerials" value="{{ $serial->serial_no }}" class="accent-brand-500">
                                        {{ $serial->serial_no }} <span class="text-ink-muted">({{ $serial->lot->lot_no ?? 'lotsuz' }})</span>
                                    </label>
                                @empty
                                    <span class="text-ink-muted col-span-full">Bu depoda stokta seri yok.</span>
                                @endforelse
                            </div>
                        @endif
                        @error('selectedSerials') <span class="text-status-critical text-[12px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                @endif
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Çıkış Nedeni</label>
                    <select wire:model="reasonCategory" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Seçiniz</option>
                        @foreach ($reasons as $reasonOption)
                            <option value="{{ $reasonOption->value }}">{{ $reasonOption->label() }}</option>
                        @endforeach
                    </select>
                    <p class="text-[12px] text-ink-muted mt-1">Tedarikçiye iade için <a href="{{ route('returns.index') }}" wire:navigate class="text-brand-600 hover:underline">İadeler</a>, depolar arası aktarım için <a href="{{ route('transfers.index') }}" wire:navigate class="text-brand-600 hover:underline">Transferler</a> ekranını kullanın.</p>
                    @error('reasonCategory') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Açıklama @if ($selectedProduct?->is_controlled)<span class="text-status-critical">(kontrollü ürün — zorunlu)</span>@endif</label>
                    <input type="text" wire:model="reasonNote" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('reasonNote') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
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
