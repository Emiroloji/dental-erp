<?php

use App\Domain\Reporting\Services\StockReportService;
use App\Domain\Reporting\Support\ReportType;
use App\Http\Livewire\Concerns\WithReportFilters;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination, WithReportFilters;

    public string $search = '';

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

    /**
     * Stok durumu bir andır (tarih filtresi yok); şube seçimi kapsamla kesiştirilir.
     *
     * @return array<int, int>|null
     */
    private function branchIds(): ?array
    {
        return $this->reportFilters()->branchScope($this->accessibleBranchIds());
    }

    public function export(string $format): void
    {
        $this->queueReportExport(ReportType::Stock, $format, ['search' => $this->search]);
    }

    public function with(StockReportService $reports): array
    {
        $products = $reports->query($this->filters())->paginate(15);

        $products->setCollection($reports->rows($products->getCollection(), $this->warehouseId ?: null, $this->branchIds()));

        return [
            'rows' => $products,
            'options' => $this->filterOptions(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Raporlar</h1>
            <p class="text-[14px] text-ink-muted mt-1">Ürün, kategori, tedarikçi, şube ve depo bazlı güncel stok özeti.</p>
        </div>
        <div class="flex gap-2 shrink-0">
            <button wire:click="export('xlsx')" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">Excel'e Aktar</button>
            <button wire:click="export('pdf')" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">PDF'e Aktar</button>
        </div>
    </div>

    <x-report-tabs />

    <x-report-filters :options="$options" :dates="false">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Ürün adı veya kodu ara" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
    </x-report-filters>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
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
                        <td class="px-5 py-3 text-right tabular-nums">{{ Number::format($row['quantity'], precision: 2) }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ Number::format($row['value'], precision: 2) }}</td>
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
