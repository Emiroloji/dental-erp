<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Services\StockReportService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public string $categoryId = '';

    public string $supplierId = '';

    public string $warehouseId = '';

    public string $search = '';

    public function updatingCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatingSupplierId(): void
    {
        $this->resetPage();
    }

    public function updatingWarehouseId(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array{category_id: ?string, supplier_id: ?string, warehouse_id: ?string, search: ?string}
     */
    private function filters(): array
    {
        return [
            'category_id' => $this->categoryId ?: null,
            'supplier_id' => $this->supplierId ?: null,
            'warehouse_id' => $this->warehouseId ?: null,
            'search' => $this->search ?: null,
        ];
    }

    public function exportExcel(StockReportService $reports)
    {
        return $reports->exportExcel($this->filters());
    }

    public function exportPdf(StockReportService $reports)
    {
        return $reports->exportPdf($this->filters());
    }

    public function with(StockReportService $reports): array
    {
        $products = $reports->query($this->filters())->paginate(15);

        $products->setCollection($reports->rows($products->getCollection(), $this->warehouseId ?: null));

        return [
            'rows' => $products,
            'categories' => Category::where('status', 'active')->orderBy('name')->get(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(),
            'warehouses' => Warehouse::whereHas('branch')->with('branch')->where('status', 'active')->orderBy('name')->get(),
        ];
    }
};
?>

<div>
    <div class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Stok Raporu</h1>
            <p class="text-[14px] text-ink-muted mt-1">Ürün, kategori, tedarikçi ve depo bazlı stok özeti.</p>
        </div>
        <div class="flex gap-2 shrink-0">
            <button wire:click="exportExcel" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">Excel'e Aktar</button>
            <button wire:click="exportPdf" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">PDF'e Aktar</button>
        </div>
    </div>

    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <div class="relative flex-1">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-muted"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3" stroke-linecap="round"/></svg>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Ürün adı veya kodu ara" class="w-full border border-line rounded-md pl-9 pr-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
        </div>
        <select wire:model.live="categoryId" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Kategoriler</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="supplierId" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Tedarikçiler</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="warehouseId" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
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
                    <th class="px-5 py-3 font-medium">Ürün</th>
                    <th class="px-5 py-3 font-medium">Kategori</th>
                    <th class="px-5 py-3 font-medium">Tedarikçi</th>
                    <th class="px-5 py-3 font-medium text-right">Mevcut Stok</th>
                    <th class="px-5 py-3 font-medium text-right">Toplam Değer</th>
                    <th class="px-5 py-3 font-medium">Seviye</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $row)
                    <tr wire:key="report-row-{{ $row['product']->id }}">
                        <td class="px-5 py-3">
                            <p>{{ $row['product']->name }}</p>
                            <p class="text-[12px] text-ink-muted">{{ $row['product']->code ?? '—' }}</p>
                        </td>
                        <td class="px-5 py-3 text-ink-muted">{{ $row['product']->category?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $row['product']->supplier?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ number_format($row['quantity'], 2) }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ number_format($row['value'], 2) }}</td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $row['level']->badgeClasses() }}">{{ $row['level']->label() }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-ink-muted text-[13px]">Kayıt bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($rows->hasPages())
            <div class="px-5 py-3 border-t border-line">
                {{ $rows->links() }}
            </div>
        @endif
    </section>
</div>
