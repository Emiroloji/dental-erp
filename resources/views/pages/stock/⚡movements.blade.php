<?php

use App\Domain\Access\Support\Module;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Exceptions\InsufficientStockException;
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

    public string $typeFilter = '';

    public string $warehouseFilter = '';

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingWarehouseFilter(): void
    {
        $this->resetPage();
    }

    public function cancel(int $movementId, StockMovementService $service): void
    {
        Gate::authorize('stock_movement.update');

        try {
            $movement = StockMovement::whereHas('lot.product')->inBranches($this->branchIds())->findOrFail($movementId);
        } catch (ModelNotFoundException) {
            // Livewire's test harness doesn't convert ModelNotFoundException into a 404
            // response the way a real HTTP request does, so we convert it explicitly —
            // this also keeps error handling consistent across all three write screens.
            abort(404);
        }

        if ($movement->type === StockMovementType::Cancel) {
            session()->flash('error', 'İptal hareketleri tekrar iptal edilemez.');

            return;
        }

        $alreadyCancelled = StockMovement::whereHas('lot.product')
            ->where('type', StockMovementType::Cancel->value)
            ->where('related_entity_type', StockMovement::class)
            ->where('related_entity_id', $movement->id)
            ->exists();

        if ($alreadyCancelled) {
            session()->flash('error', 'Bu hareket zaten iptal edilmiş.');

            return;
        }

        try {
            $service->cancel($movement, auth()->user());
        } catch (InsufficientStockException|InactiveLocationException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', 'Hareket iptal edildi.');
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
            ->with(['lot.product', 'warehouse', 'actor'])
            ->when($this->typeFilter, fn ($query) => $query->where('type', $this->typeFilter))
            ->when($this->warehouseFilter, fn ($query) => $query->where('warehouse_id', $this->warehouseFilter))
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        $cancelledMovementIds = StockMovement::whereHas('lot.product')
            ->where('type', StockMovementType::Cancel->value)
            ->where('related_entity_type', StockMovement::class)
            ->pluck('related_entity_id')
            ->all();

        return [
            'movements' => $movements,
            'cancelledMovementIds' => $cancelledMovementIds,
            'warehouses' => Warehouse::whereHas('branch')->inBranches($this->branchIds())->with('branch')->where('status', 'active')->orderBy('name')->get(),
            'types' => StockMovementType::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Stok Hareketleri</h1>
        <p class="text-[14px] text-ink-muted mt-1">Tüm stok hareketlerini görüntüle, gerekirse iptal et.</p>
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

    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <select wire:model.live="typeFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Hareket Tipleri</option>
            @foreach ($types as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </select>
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
                    <th class="px-5 py-3 font-medium">Tarih</th>
                    <th class="px-5 py-3 font-medium">Tip</th>
                    <th class="px-5 py-3 font-medium">Ürün</th>
                    <th class="px-5 py-3 font-medium">Depo</th>
                    <th class="px-5 py-3 font-medium">Lot</th>
                    <th class="px-5 py-3 font-medium">Personel</th>
                    <th class="px-5 py-3 font-medium">Neden</th>
                    <th class="px-5 py-3 font-medium text-right">Miktar</th>
                    <th class="px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($movements as $movement)
                    <tr wire:key="movement-{{ $movement->id }}">
                        <td class="px-5 py-3 text-ink-muted">{{ $movement->created_at->format('d.m.Y H:i') }}</td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">{{ $movement->type->label() }}</span>
                        </td>
                        <td class="px-5 py-3">{{ $movement->lot->product->name }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $movement->warehouse->name }}</td>
                        <td class="px-5 py-3 font-mono text-[13px] text-ink-muted">{{ $movement->lot->lot_no ?? '—' }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $movement->actor?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $movement->reason ?? '—' }}</td>
                        <td class="px-5 py-3 text-right tabular-nums @if((float) $movement->quantity < 0) text-status-critical @else text-status-good @endif">
                            {{ (float) $movement->quantity > 0 ? '+' : '' }}{{ Number::format((float) $movement->quantity, precision: 2) }}
                        </td>
                        <td class="px-5 py-3 text-right">
                            @can('stock_movement.update')
                                @if ($movement->type !== \App\Domain\Stock\Support\StockMovementType::Cancel && ! in_array($movement->id, $cancelledMovementIds))
                                    <button wire:click="cancel({{ $movement->id }})" wire:confirm="Bu hareketi iptal etmek istediğine emin misin?" class="text-[13px] text-status-critical hover:underline">
                                        İptal Et
                                    </button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-5 py-8 text-center text-ink-muted text-[13px]">Kayıt bulunamadı.</td>
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
</div>
