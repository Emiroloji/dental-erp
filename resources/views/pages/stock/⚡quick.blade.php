<?php

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Exceptions\ScanException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\ScanResolver;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\ExpiredUsageWarning;
use App\Domain\Stock\Support\StockOutReason;
use App\Support\UnitConverter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Component;

/**
 * Barkod/QR ile hızlı stok giriş-çıkışı (Faz 3 — Aşama 20). USB/Bluetooth
 * okuyucu klavye gibi yazıp Enter gönderir; telefonda kamera ile okunur.
 * Tüm stok değişiklikleri StockMovementService'ten geçer.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    /** in | out */
    #[Session]
    public string $mode = 'out';

    #[Session]
    public string $warehouse_id = '';

    public string $scanCode = '';

    public ?int $productId = null;

    public string $lot_id = '';

    public string $unit = '';

    public string $quantity = '1';

    public string $reasonCategory = 'clinical_use';

    public string $lot_no = '';

    public string $expiry_date = '';

    public string $unit_cost = '';

    public string $scanError = '';

    /** @var array<int, array{time: string, mode: string, text: string}> bu oturumdaki son işlemler */
    #[Session]
    public array $recent = [];

    public function mount(): void
    {
        if (blank($this->warehouse_id) || ! $this->warehouses()->contains('id', (int) $this->warehouse_id)) {
            $this->warehouse_id = (string) ($this->warehouses()->firstWhere('is_default', true)?->id ?? $this->warehouses()->first()?->id ?? '');
        }
    }

    public function updatedMode(): void
    {
        $this->clearProduct();
    }

    public function updatedWarehouseId(): void
    {
        $this->lot_id = '';
    }

    public function scan(ScanResolver $resolver): void
    {
        $this->scanError = '';
        $code = $this->scanCode;
        $this->scanCode = '';

        try {
            $result = $resolver->resolve($code, $this->branchIds());
        } catch (ScanException $e) {
            $this->scanError = $e->getMessage();
            $this->clearProduct();

            return;
        }

        $product = $result['product'];
        $lot = $result['lot'];

        $this->productId = $product->id;
        $this->unit = $product->base_unit;
        $this->quantity = '1';
        $this->lot_id = '';
        $this->lot_no = '';
        $this->expiry_date = '';
        $this->unit_cost = (string) $product->purchase_price;

        if ($lot) {
            // Lot etiketi: depo lotun deposudur; girişte aynı lota eklenir, çıkışta o lottan düşülür.
            if ($lot->warehouse->isOperational() && $this->warehouses()->contains('id', $lot->warehouse_id)) {
                $this->warehouse_id = (string) $lot->warehouse_id;
            }

            $this->lot_id = (string) $lot->id;
            $this->lot_no = (string) $lot->lot_no;
            $this->expiry_date = (string) $lot->expiry_date?->toDateString();
            $this->unit_cost = (string) $lot->unit_cost;
        }

        // GS1 DataMatrix (ÜTS): kutudaki lot ve SKT forma dolar; çıkışta seçili
        // depoda aynı numaralı lot varsa o lot seçilir.
        if ($gs1 = $result['gs1'] ?? null) {
            $this->lot_no = (string) $gs1['lot_no'];
            $this->expiry_date = (string) $gs1['expiry_date'];

            if ($this->mode === 'out' && filled($gs1['lot_no'])) {
                $matching = StockLot::where('product_id', $product->id)->where('warehouse_id', $this->warehouse_id)->where('lot_no', $gs1['lot_no'])->where('quantity', '>', 0)->first();
                $this->lot_id = (string) ($matching?->id ?? '');
            }
        }
    }

    public function submit(StockMovementService $stock, UnitConverter $units): void
    {
        Gate::authorize('stock_movement.create');

        $product = $this->product();

        if (! $product) {
            $this->scanError = 'Önce bir ürün okutun.';

            return;
        }

        $validated = $this->validate([
            'warehouse_id' => ['required', Rule::in($this->warehouses()->pluck('id')->all())],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit' => ['required', Rule::in($product->unitOptions())],
            'reasonCategory' => [Rule::requiredIf($this->mode === 'out'), Rule::in(array_column(StockOutReason::selectable(), 'value'))],
            'lot_no' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ], [
            'warehouse_id.in' => 'Yalnızca kendi kapsamındaki işleme açık bir depoda işlem yapabilirsin.',
            'quantity.gt' => 'Miktar sıfırdan büyük olmalı.',
        ]);

        $warehouse = Warehouse::findOrFail($validated['warehouse_id']);
        $baseQuantity = $units->toBaseUnit($product, (float) $validated['quantity'], $validated['unit']);
        $unitNote = $validated['unit'] === $product->base_unit ? '' : " ({$validated['quantity']} {$validated['unit']})";

        try {
            if ($this->mode === 'in') {
                $stock->in($product, $warehouse, $baseQuantity, [
                    'lot_no' => $validated['lot_no'] ?: null,
                    'expiry_date' => $validated['expiry_date'] ?: null,
                    'unit_cost' => $validated['unit_cost'] === '' || $validated['unit_cost'] === null ? (float) $product->purchase_price : (float) $validated['unit_cost'],
                ], auth()->user(), 'Hızlı giriş (barkod)'.$unitNote);
            } else {
                $reason = StockOutReason::from($validated['reasonCategory']);
                $lot = filled($this->lot_id)
                    ? StockLot::whereHas('product')->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->find($this->lot_id)
                    : null;

                if (filled($this->lot_id) && ! $lot) {
                    $this->addError('lot_id', 'Okutulan lot seçili depoda değil.');

                    return;
                }

                $warning = ExpiredUsageWarning::for(
                    $stock->out($product, $warehouse, $baseQuantity, $lot, auth()->user(), "{$reason->label()} — hızlı çıkış (barkod){$unitNote}", $reason),
                    $reason,
                );
            }
        } catch (InsufficientStockException $e) {
            $this->addError('quantity', $e->getMessage());

            return;
        } catch (InactiveLocationException $e) {
            $this->addError('warehouse_id', $e->getMessage());

            return;
        }

        $quantityText = Number::format($baseQuantity, maxPrecision: 2).' '.$product->base_unit;
        array_unshift($this->recent, [
            'time' => now()->format('H:i'),
            'mode' => $this->mode,
            'text' => ($this->mode === 'in' ? '+' : '−')."{$quantityText} {$product->name} · {$warehouse->name}",
        ]);
        $this->recent = array_slice($this->recent, 0, 10);

        session()->flash('status', ($this->mode === 'in' ? 'Giriş' : 'Çıkış')." kaydedildi: {$quantityText} {$product->name}. Depoda kalan: ".Number::format($this->available($product), maxPrecision: 2)." {$product->base_unit}.");

        if ($warning ?? null) {
            session()->flash('warning', $warning);
        }
        $this->clearProduct();
        $this->dispatch('scan-ready');
    }

    public function clearProduct(): void
    {
        $this->reset(['productId', 'lot_id', 'unit', 'quantity', 'lot_no', 'expiry_date', 'unit_cost']);
        $this->resetValidation();
    }

    private function product(): ?Product
    {
        return $this->productId ? Product::where('status', 'active')->find($this->productId) : null;
    }

    private function available(Product $product): float
    {
        return (float) StockLot::where('product_id', $product->id)->where('warehouse_id', $this->warehouse_id ?: 0)->sum('quantity');
    }

    /**
     * @return array<int, int>|null
     */
    private function branchIds(): ?array
    {
        return auth()->user()->accessibleBranchIds(Module::StockMovement);
    }

    private function warehouses()
    {
        return Warehouse::operational()->inBranches($this->branchIds())->with('branch')->orderBy('name')->get();
    }

    public function with(): array
    {
        $product = $this->product();

        return [
            'product' => $product,
            'available' => $product ? $this->available($product) : 0,
            'lots' => $product && $this->mode === 'out' && filled($this->warehouse_id)
                ? StockLot::where('product_id', $product->id)->where('warehouse_id', $this->warehouse_id)->where('quantity', '>', 0)->orderByRaw('expiry_date IS NULL, expiry_date ASC')->get()
                : collect(),
            'units' => $product ? $product->unitOptions() : [],
            'conversions' => $product ? collect($product->conversion_rules ?? []) : collect(),
            'warehouses' => $this->warehouses(),
            'reasons' => StockOutReason::selectable(),
        ];
    }
};
?>

<div x-data="quickScan" x-on:scan-ready.window="focusInput()">
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Hızlı İşlem</h1>
        <p class="text-[14px] text-ink-muted mt-1">Barkodu okuyucuyla veya telefon kamerasıyla okutun; miktarı girip tek dokunuşla stoğa işleyin.</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[14px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @if (session('warning'))
        <div class="mb-4 rounded-md bg-status-warn-bg border border-status-warn/20 text-status-warn text-[14px] px-4 py-3">{{ session('warning') }}</div>
    @endif

    <div class="grid grid-cols-2 gap-2 mb-4" role="tablist">
        <button type="button" wire:click="$set('mode', 'out')" class="rounded-md py-3 text-[15px] font-medium border {{ $mode === 'out' ? 'bg-panel-900 text-white border-panel-900' : 'bg-surface border-line text-ink-muted' }}">Çıkış</button>
        <button type="button" wire:click="$set('mode', 'in')" class="rounded-md py-3 text-[15px] font-medium border {{ $mode === 'in' ? 'bg-panel-900 text-white border-panel-900' : 'bg-surface border-line text-ink-muted' }}">Giriş</button>
    </div>

    <div class="mb-4">
        <label class="block text-[13px] text-ink-muted mb-1.5">Depo</label>
        <select wire:model.live="warehouse_id" class="w-full border border-line rounded-md px-3 py-3 text-[15px] bg-surface">
            @foreach ($warehouses as $warehouse)
                <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
            @endforeach
        </select>
        @error('warehouse_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
    </div>

    <form wire:submit="scan" class="mb-2 flex gap-2">
        <input x-ref="scanInput" type="text" wire:model="scanCode" autofocus autocomplete="off" inputmode="text" placeholder="Barkodu okutun veya yazın"
            class="flex-1 border border-line rounded-md px-3 py-3 text-[15px] font-mono bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
        <button type="submit" class="border border-line rounded-md px-4 text-[14px] bg-surface hover:bg-canvas">Bul</button>
        <button type="button" x-on:click="openCamera()" class="bg-panel-900 text-white rounded-md px-4 text-[14px] font-medium" title="Kamerayla okut">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2M7 12h10"/></svg>
        </button>
    </form>
    @if ($scanError)
        <p class="mb-4 text-status-critical text-[13px]">{{ $scanError }}</p>
    @endif

    <div x-show="cameraOpen" x-cloak class="mb-4 rounded-lg overflow-hidden border border-line bg-black relative">
        <video x-ref="video" class="w-full max-h-[50vh] object-cover" playsinline muted></video>
        <button type="button" x-on:click="closeCamera()" class="absolute top-2 right-2 bg-white/90 text-ink rounded-md px-3 py-1.5 text-[13px]">Kapat</button>
        <p x-show="cameraError" x-text="cameraError" class="absolute bottom-0 inset-x-0 bg-status-critical text-white text-[13px] px-3 py-2"></p>
    </div>

    @if ($product)
        <section class="border border-line rounded-lg bg-surface px-4 py-4 mb-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[17px] font-medium text-ink">{{ $product->name }}</p>
                    <p class="text-[13px] text-ink-muted">{{ $product->code ?? '' }} {{ $product->barcode ? '· '.$product->barcode : '' }}</p>
                </div>
                <button type="button" wire:click="clearProduct" class="text-[13px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
            <p class="mt-2 text-[14px]">Bu depoda: <span class="font-medium tabular-nums">{{ Number::format($available, maxPrecision: 2) }} {{ $product->base_unit }}</span>
                @if ($conversions->isNotEmpty()) <span class="text-ink-muted">({{ app(\App\Support\UnitConverter::class)->toCompoundDisplay($product, $available) }})</span> @endif
            </p>

            <form wire:submit="submit" class="mt-4 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1">Miktar</label>
                        <input type="number" step="0.01" min="0" wire:model="quantity" inputmode="decimal" class="w-full border border-line rounded-md px-3 py-3 text-[17px] tabular-nums">
                        @error('quantity') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1">Birim</label>
                        <select wire:model="unit" class="w-full border border-line rounded-md px-3 py-3 text-[15px]">
                            @foreach ($units as $unitOption)
                                <option value="{{ $unitOption }}">{{ $unitOption }}@if ($unitOption !== $product->base_unit) (= {{ Number::format((float) $conversions->firstWhere('unit', $unitOption)['factor'], maxPrecision: 2) }} {{ $product->base_unit }})@endif</option>
                            @endforeach
                        </select>
                        @error('unit') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if ($mode === 'out')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1">Neden</label>
                            <select wire:model="reasonCategory" class="w-full border border-line rounded-md px-3 py-3 text-[15px]">
                                @foreach ($reasons as $reason)
                                    <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1">Lot</label>
                            <select wire:model="lot_id" class="w-full border border-line rounded-md px-3 py-3 text-[15px]">
                                <option value="">Otomatik (SKT'si en yakın — FEFO)</option>
                                @foreach ($lots as $lot)
                                    <option value="{{ $lot->id }}">{{ $lot->lot_no ?? 'Lot #'.$lot->id }} · SKT {{ $lot->expiry_date?->format('d.m.Y') ?? '—' }}{{ $lot->isExpired() ? ' (GEÇTİ)' : '' }} · {{ Number::format((float) $lot->quantity, maxPrecision: 2) }}</option>
                                @endforeach
                            </select>
                            @error('lot_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1">Lot no</label>
                            <input type="text" wire:model="lot_no" class="w-full border border-line rounded-md px-3 py-3 text-[15px] font-mono">
                        </div>
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1">SKT</label>
                            <input type="date" wire:model="expiry_date" class="w-full border border-line rounded-md px-3 py-3 text-[15px]">
                            @error('expiry_date') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-[13px] text-ink-muted mb-1">Birim maliyet (ana birim)</label>
                            <input type="number" step="0.01" min="0" wire:model="unit_cost" class="w-full border border-line rounded-md px-3 py-3 text-[15px]">
                        </div>
                    </div>
                @endif

                @can('stock_movement.create')
                    <button type="submit" class="w-full rounded-md py-3.5 text-[16px] font-medium text-white {{ $mode === 'in' ? 'bg-status-good' : 'bg-panel-900' }}">
                        {{ $mode === 'in' ? 'Girişi Kaydet' : 'Çıkışı Kaydet' }}
                    </button>
                @endcan
            </form>
        </section>
    @endif

    @if ($recent !== [])
        <section>
            <h2 class="text-[13px] text-ink-muted mb-2">Bu oturumdaki son işlemler</h2>
            <ul class="border border-line rounded-lg bg-surface divide-y divide-line text-[14px]">
                @foreach ($recent as $item)
                    <li class="px-4 py-2.5 flex gap-3"><span class="text-ink-muted tabular-nums">{{ $item['time'] }}</span><span class="{{ $item['mode'] === 'in' ? 'text-status-good' : '' }}">{{ $item['text'] }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

@script
<script>
    Alpine.data('quickScan', () => ({
        cameraOpen: false,
        cameraError: '',
        controls: null,
        focusInput() {
            this.$nextTick(() => this.$refs.scanInput?.focus());
        },
        async openCamera() {
            this.cameraError = '';
            this.cameraOpen = true;
            try {
                this.controls = await window.startBarcodeScanner(this.$refs.video, (text) => {
                    this.closeCamera();
                    $wire.set('scanCode', text, false);
                    $wire.scan();
                });
            } catch (error) {
                this.cameraError = 'Kamera açılamadı: ' + (error?.message ?? error) + ' (Kamera yalnızca HTTPS veya localhost üzerinde çalışır.)';
            }
        },
        closeCamera() {
            this.controls?.stop();
            this.controls = null;
            this.cameraOpen = false;
            this.focusInput();
        },
    }));
</script>
@endscript
