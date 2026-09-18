<?php

use App\Domain\Access\Support\Module;
use App\Domain\Organization\Models\Branch;
use App\Domain\Reporting\Services\WarehouseStockReportService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public string $branchId = '';

    public string $search = '';

    public function mount(): void
    {
        $this->branchId = (string) ($this->allowedBranchIds()[0] ?? '');
    }

    /**
     * Kapsam dışı bir şube seçilemez; seçilirse ilk erişilebilir şubeye döner.
     */
    public function updatedBranchId(): void
    {
        if (! in_array((int) $this->branchId, $this->allowedBranchIds(), true)) {
            $this->branchId = (string) ($this->allowedBranchIds()[0] ?? '');
        }

        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<int, int|null>|null
     */
    private function branchScope(): ?array
    {
        return auth()->user()->accessibleBranchIds(Module::StockMovement, Module::Reports);
    }

    /**
     * @return array<int, int>
     */
    private function allowedBranchIds(): array
    {
        return Branch::where('status', 'active')
            ->when($this->branchScope() !== null, fn ($query) => $query->whereIn('id', $this->branchScope()))
            ->orderBy('name')
            ->pluck('id')
            ->all();
    }

    public function with(WarehouseStockReportService $reports): array
    {
        $branch = filled($this->branchId) ? Branch::find($this->branchId) : null;

        return [
            'branchSummaries' => $reports->branchSummaries($this->branchScope()),
            'branch' => $branch,
            'report' => $branch ? $reports->forBranch($branch, $this->search ?: null) : null,
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Depo Stokları</h1>
        <p class="text-[14px] text-ink-muted mt-1">Stok depo seviyesinde tutulur; şube toplamı o şubedeki depoların toplamıdır.</p>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-hidden mb-8">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Şube</th>
                    <th class="px-5 py-3 font-medium text-right">Aktif Depo</th>
                    <th class="px-5 py-3 font-medium text-right">Toplam Miktar</th>
                    <th class="px-5 py-3 font-medium text-right">Toplam Değer</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($branchSummaries as $summary)
                    <tr wire:key="summary-{{ $summary['id'] }}" wire:click="$set('branchId', '{{ $summary['id'] }}')" @class(['cursor-pointer hover:bg-canvas', 'bg-canvas' => (string) $summary['id'] === $branchId])>
                        <td class="px-5 py-3 font-medium">{{ $summary['name'] }}</td>
                        <td class="px-5 py-3 text-right tabular-nums text-ink-muted">{{ $summary['warehouse_count'] }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ Number::format($summary['quantity'], precision: 2) }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ Number::format($summary['value'], precision: 2) }} ₺</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-8 text-center text-ink-muted text-[13px]">Erişebildiğin aktif şube yok.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    @if ($report)
        <div class="mb-4 flex flex-col sm:flex-row gap-3">
            <select wire:model.live="branchId" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @foreach ($branchSummaries as $summary)
                    <option value="{{ $summary['id'] }}">{{ $summary['name'] }}</option>
                @endforeach
            </select>
            <div class="relative flex-1">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-muted"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3" stroke-linecap="round"/></svg>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Ürün adı veya kodu ara" class="w-full border border-line rounded-md pl-9 pr-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            </div>
        </div>

        <section class="border border-line rounded-lg bg-surface overflow-x-auto">
            <table class="w-full text-[14px]">
                <thead>
                    <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                        <th class="px-5 py-3 font-medium">Ürün</th>
                        @foreach ($report['warehouses'] as $warehouse)
                            <th class="px-5 py-3 font-medium text-right whitespace-nowrap">{{ $warehouse->name }}</th>
                        @endforeach
                        <th class="px-5 py-3 font-medium text-right whitespace-nowrap text-ink">Şube Toplamı</th>
                        <th class="px-5 py-3 font-medium text-right">Değer</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($report['rows'] as $row)
                        <tr wire:key="row-{{ $row['product']->id }}">
                            <td class="px-5 py-3">
                                {{ $row['product']->name }}
                                @if ($row['product']->code)
                                    <span class="font-mono text-[12px] text-ink-muted">{{ $row['product']->code }}</span>
                                @endif
                            </td>
                            @foreach ($report['warehouses'] as $warehouse)
                                <td class="px-5 py-3 text-right tabular-nums {{ $row['quantities'][$warehouse->id] > 0 ? '' : 'text-ink-muted' }}">
                                    {{ Number::format($row['quantities'][$warehouse->id], precision: 2) }}
                                </td>
                            @endforeach
                            <td class="px-5 py-3 text-right tabular-nums font-medium">{{ Number::format($row['total'], precision: 2) }} {{ $row['product']->base_unit }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-ink-muted">{{ Number::format($row['value'], precision: 2) }} ₺</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $report['warehouses']->count() + 3 }}" class="px-5 py-8 text-center text-ink-muted text-[13px]">Bu şubede stokta ürün yok.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="border-t border-line bg-canvas text-[13px]">
                        <td class="px-5 py-3 font-medium">Depo Toplamı</td>
                        @foreach ($report['warehouses'] as $warehouse)
                            <td class="px-5 py-3 text-right tabular-nums font-medium">{{ Number::format($report['warehouseTotals'][$warehouse->id], precision: 2) }}</td>
                        @endforeach
                        <td class="px-5 py-3 text-right tabular-nums font-medium">{{ Number::format($report['branchTotal'], precision: 2) }}</td>
                        <td class="px-5 py-3 text-right tabular-nums font-medium">{{ Number::format($report['branchValue'], precision: 2) }} ₺</td>
                    </tr>
                </tfoot>
            </table>

            @if ($report['paginator']->hasPages())
                <div class="px-5 py-3 border-t border-line">
                    {{ $report['paginator']->links() }}
                </div>
            @endif
        </section>
    @endif
</div>
