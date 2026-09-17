<?php

use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public string $warehouseFilter = '';

    public string $search = '';

    public function updatingWarehouseFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $lots = StockLot::query()
            ->whereHas('product')
            ->with(['product', 'warehouse.branch'])
            ->when($this->warehouseFilter, fn ($query) => $query->where('warehouse_id', $this->warehouseFilter))
            ->when($this->search, fn ($query) => $query->whereHas('product', function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%");
            }))
            ->orderBy('warehouse_id')
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->paginate(15);

        return [
            'lots' => $lots,
            'warehouses' => Warehouse::whereHas('branch')->with('branch')->where('status', 'active')->orderBy('name')->get(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Stok Durumu</h1>
        <p class="text-[14px] text-ink-muted mt-1">Depo ve lot bazlı güncel stok seviyelerini görüntüle.</p>
    </div>

    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <div class="relative flex-1">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-muted"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3" stroke-linecap="round"/></svg>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Ürün adı veya kodu ara" class="w-full border border-line rounded-md pl-9 pr-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
        </div>
        <select wire:model.live="warehouseFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Depolar</option>
            @foreach ($warehouses as $warehouse)
                <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
            @endforeach
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-hidden">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Depo</th>
                    <th class="px-5 py-3 font-medium">Ürün</th>
                    <th class="px-5 py-3 font-medium">Lot No</th>
                    <th class="px-5 py-3 font-medium">SKT</th>
                    <th class="px-5 py-3 font-medium">Birim Maliyet</th>
                    <th class="px-5 py-3 font-medium text-right">Miktar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($lots as $lot)
                    <tr wire:key="lot-{{ $lot->id }}">
                        <td class="px-5 py-3 text-ink-muted">{{ $lot->warehouse->branch->name }} — {{ $lot->warehouse->name }}</td>
                        <td class="px-5 py-3">{{ $lot->product->name }}</td>
                        <td class="px-5 py-3 font-mono text-[13px] text-ink-muted">{{ $lot->lot_no ?? '—' }}</td>
                        <td class="px-5 py-3">
                            @if ($lot->expiry_date)
                                <span @class(['text-status-critical' => $lot->isExpired()])>{{ $lot->expiry_date->format('d.m.Y') }}</span>
                            @else
                                <span class="text-ink-muted">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-ink-muted tabular-nums">{{ number_format((float) $lot->unit_cost, 2) }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">
                            @if ((float) $lot->quantity <= 0)
                                <span class="text-ink-muted">Tükendi</span>
                            @else
                                {{ number_format((float) $lot->quantity, 2) }} {{ $lot->product->base_unit }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-ink-muted text-[13px]">Kayıt bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($lots->hasPages())
            <div class="px-5 py-3 border-t border-line">
                {{ $lots->links() }}
            </div>
        @endif
    </section>
</div>
