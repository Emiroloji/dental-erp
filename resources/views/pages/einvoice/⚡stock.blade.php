<?php

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\EInvoice\Services\EInvoiceStockService;
use App\Domain\Organization\Models\Warehouse;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Gelen e-faturadan stok girişi (Aşama 56): depo seçilir, faturadan doldurulan
 * satırlar (ürün, adet, lot, son kullanma tarihi, seriler) kontrol edilip
 * onaylanır. Girişler hep birlikte yapılır ve faturaya bağlanır.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    public int $invoiceId;

    public string $warehouseId = '';

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function mount(int $invoice, EInvoiceStockService $stock): void
    {
        $model = EInvoice::findOrFail($invoice);
        $this->invoiceId = $model->id;
        $this->rows = $stock->draft($model);

        $warehouses = $this->warehouses();
        if ($warehouses->count() === 1) {
            $this->warehouseId = (string) $warehouses->first()->id;
        }
    }

    public function receive(EInvoiceStockService $stock): void
    {
        $this->authorize('stock_movement.create');

        $warehouse = $this->warehouses()->firstWhere('id', (int) $this->warehouseId);

        if (! $warehouse) {
            $this->addError('warehouseId', 'Girişin yapılacağı depoyu seçin.');

            return;
        }

        try {
            $stock->receive(EInvoice::findOrFail($this->invoiceId), $warehouse, $this->rows, auth()->user());
        } catch (EInvoiceException $e) {
            $this->addError('rows', $e->getMessage());

            return;
        }

        session()->flash('status', 'Fatura kalemleri stoğa alındı.');
        $this->redirect(route('einvoice.show', $this->invoiceId));
    }

    private function warehouses()
    {
        return Warehouse::operational()->inBranches(auth()->user()->accessibleBranchIds(Module::StockMovement))->with('branch')->orderBy('name')->get();
    }

    public function with(): array
    {
        $productIds = collect($this->rows)->pluck('product_id')->filter()->all();

        return [
            'invoice' => $invoice = EInvoice::findOrFail($this->invoiceId),
            'refusal' => app(EInvoiceStockService::class)->refusal($invoice),
            'warehouses' => $this->warehouses(),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'base_unit', 'tracks_serials', 'cold_chain']),
            'selected' => Product::whereIn('id', $productIds)->get()->keyBy('id'),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <a href="{{ route('einvoice.show', $invoice->id) }}" class="text-[13px] text-ink-muted hover:text-ink">e-Fatura {{ $invoice->invoice_number }}</a>
        <h1 class="text-[22px] font-medium tracking-tight text-ink mt-1">Faturadan stoğa al</h1>
        <p class="text-[14px] text-ink-muted mt-1">Kalemler faturadan doldurulmuştur; ürünü, adedi, lotu ve seri numaralarını kutularla karşılaştırıp onaylayın. Seçili kalemlerin hepsi birlikte girer ya da hiçbiri girmez.</p>
    </div>

    @if (! $refusal)
        <div class="mb-5 max-w-xs">
            <label for="warehouse" class="block text-[13px] text-ink-muted mb-1.5">Depo</label>
            <select id="warehouse" wire:model="warehouseId" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                <option value="">Seçin</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
                @endforeach
            </select>
            @error('warehouseId') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
        </div>

        @error('rows')
            <div class="mb-4 rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">{{ $message }}</div>
        @enderror

        <ul class="space-y-3">
            @foreach ($rows as $i => $row)
                @php($product = $selected[$row['product_id']] ?? null)
                <li wire:key="row-{{ $i }}" @class(['border rounded-lg bg-surface px-4 py-4', 'border-line' => $row['include'], 'border-line opacity-70' => ! $row['include']])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <label class="flex items-start gap-2.5 min-w-0">
                            <input type="checkbox" wire:model.live="rows.{{ $i }}.include" class="accent-brand-500 w-4 h-4 mt-1">
                            <span class="min-w-0">
                                <span class="block text-[15px] text-ink">{{ $row['name'] }}</span>
                                @if ($row['warning']) <span class="block text-[12px] text-status-warn mt-0.5">{{ $row['warning'] }}</span> @endif
                            </span>
                        </label>
                    </div>

                    @if ($row['include'])
                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-4 gap-3">
                            <div class="sm:col-span-2">
                                <label class="block text-[12px] text-ink-muted mb-1" for="product-{{ $i }}">Ürün kartı</label>
                                <select id="product-{{ $i }}" wire:model.live="rows.{{ $i }}.product_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                                    <option value="">Seçin</option>
                                    @foreach ($products as $option)
                                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[12px] text-ink-muted mb-1" for="qty-{{ $i }}">Miktar{{ $product ? ' ('.$product->base_unit.')' : '' }}</label>
                                <input id="qty-{{ $i }}" type="number" step="0.01" min="0" wire:model="rows.{{ $i }}.quantity" @readonly($product?->tracks_serials) class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums read-only:bg-canvas">
                            </div>
                            <div>
                                <label class="block text-[12px] text-ink-muted mb-1" for="lot-{{ $i }}">Lot</label>
                                <input id="lot-{{ $i }}" type="text" wire:model="rows.{{ $i }}.lot_no" class="w-full border border-line rounded-md px-3 py-2 text-[14px] font-mono">
                            </div>
                            <div>
                                <label class="block text-[12px] text-ink-muted mb-1" for="exp-{{ $i }}">Son kullanma</label>
                                <input id="exp-{{ $i }}" type="date" wire:model="rows.{{ $i }}.expiry_date" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                            </div>
                            @if ($product?->cold_chain)
                                <div>
                                    <label class="block text-[12px] text-ink-muted mb-1" for="temp-{{ $i }}">Ölçülen sıcaklık (°C)</label>
                                    <input id="temp-{{ $i }}" type="number" step="0.1" wire:model="rows.{{ $i }}.temperature" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[12px] text-ink-muted mb-1" for="tnote-{{ $i }}">Aralık dışıysa gerekçe</label>
                                    <input id="tnote-{{ $i }}" type="text" wire:model="rows.{{ $i }}.temperature_note" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                                </div>
                            @endif
                            @if ($row['serials'])
                                <div class="sm:col-span-4 text-[12px] text-ink-muted">
                                    Seriler: <span class="font-mono text-ink">{{ implode(', ', $row['serials']) }}</span>
                                </div>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-6 flex items-center gap-3">
            <button type="button" wire:click="receive" wire:loading.attr="disabled" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors disabled:opacity-50">Stoğa al</button>
            <a href="{{ route('einvoice.show', $invoice->id) }}" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</a>
        </div>
    @else
        <div class="rounded-md border border-line bg-surface px-4 py-3 text-[14px] text-ink-muted">
            {{ $refusal }}
        </div>
    @endif
</div>
