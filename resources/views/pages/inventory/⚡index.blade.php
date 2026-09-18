<?php

use App\Domain\Access\Support\Module;
use App\Domain\Inventory\Exceptions\StockCountException;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Domain\Organization\Models\Warehouse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public bool $showStart = false;

    public string $warehouse_id = '';

    public string $note = '';

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openStart(): void
    {
        Gate::authorize('stock_movement.create');

        $this->reset(['warehouse_id', 'note']);
        $this->resetValidation();
        $this->showStart = true;
    }

    public function closeStart(): void
    {
        $this->showStart = false;
    }

    public function start(StockCountService $counts): void
    {
        Gate::authorize('stock_movement.create');

        $validated = $this->validate([
            'warehouse_id' => ['required', Rule::in($this->warehouses()->modelKeys())],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'warehouse_id.in' => 'Yalnızca kendi kapsamındaki işleme açık bir depoda sayım başlatabilirsin.',
        ]);

        try {
            $count = $counts->start(Warehouse::findOrFail($validated['warehouse_id']), auth()->user(), $validated['note'] ?: null);
        } catch (StockCountException $e) {
            $this->addError('warehouse_id', $e->getMessage());

            return;
        }

        $this->redirect(route('inventory.show', $count));
    }

    private function warehouses()
    {
        return Warehouse::operational()
            ->inBranches(auth()->user()->accessibleBranchIds(Module::StockMovement))
            ->with('branch')
            ->orderBy('name')
            ->get();
    }

    public function with(): array
    {
        return [
            'counts' => StockCount::query()
                ->inBranches(auth()->user()->accessibleBranchIds(Module::StockMovement))
                ->with(['warehouse.branch', 'starter'])
                ->withCount([
                    'lines',
                    'lines as counted_lines_count' => fn ($query) => $query->whereNotNull('counted_quantity'),
                    'lines as difference_lines_count' => fn ($query) => $query->whereNotNull('counted_quantity')->whereColumn('counted_quantity', '!=', 'system_quantity'),
                ])
                ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
                ->latest()
                ->orderByDesc('id')
                ->paginate(15),
            'statuses' => StockCountStatus::cases(),
            'warehouses' => $this->showStart ? $this->warehouses() : collect(),
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Stok Sayımı</h1>
            <p class="text-[14px] text-ink-muted mt-1">Depoyu say, farkları nedenleriyle gir; Admin onayladığında fark bir düzeltme hareketi olarak stoğa işlenir.</p>
        </div>
        @can('stock_movement.create')
            <button wire:click="openStart" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Sayım Başlat
            </button>
        @endcan
    </div>

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
                    <th class="px-5 py-3 font-medium">Depo</th>
                    <th class="px-5 py-3 font-medium">Başlatan</th>
                    <th class="px-5 py-3 font-medium text-right">Sayılan</th>
                    <th class="px-5 py-3 font-medium text-right">Farklı Satır</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($counts as $count)
                    <tr wire:key="count-{{ $count->id }}">
                        <td class="px-5 py-3">
                            <a href="{{ route('inventory.show', $count) }}" class="font-mono text-[13px] text-brand-600 hover:underline">{{ $count->number() }}</a>
                            <div class="text-[12px] text-ink-muted">{{ $count->created_at->format('d.m.Y H:i') }}</div>
                        </td>
                        <td class="px-5 py-3 text-[13px]">{{ $count->warehouse->branch->name }} · {{ $count->warehouse->name }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $count->starter?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ $count->counted_lines_count }} / {{ $count->lines_count }}</td>
                        <td class="px-5 py-3 text-right tabular-nums {{ $count->difference_lines_count > 0 ? 'text-status-warn' : 'text-ink-muted' }}">{{ $count->difference_lines_count }}</td>
                        <td class="px-5 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $count->status->badgeClasses() }}">{{ $count->status->label() }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-ink-muted text-[13px]">Henüz sayım yapılmadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($counts->hasPages())
            <div class="px-5 py-3 border-t border-line">{{ $counts->links() }}</div>
        @endif
    </section>

    <x-modal :show="$showStart" title="Sayım Başlat" on-close="closeStart">
        <form wire:submit="start" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Depo</label>
                <select wire:model="warehouse_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    <option value="">Depo seçin</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
                    @endforeach
                </select>
                @error('warehouse_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Not</label>
                <input type="text" wire:model="note" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            </div>
            <p class="text-[12px] text-ink-muted">Depodaki tüm lotların şu anki sistem miktarı sayım listesine alınır. Onaya kadar stok değişmez.</p>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Başlat</button>
                <button type="button" wire:click="closeStart" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
