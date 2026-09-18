<?php

namespace App\Http\Livewire\Concerns;

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Support\ReportFilters;
use Illuminate\Support\Collection;

/**
 * Gelişmiş rapor ekranlarının ortak filtreleri (Aşama 15): tarih aralığı,
 * şube, depo, kategori, tedarikçi. Varsayılan dönem içinde bulunulan aydır.
 * Şube/depo seçenekleri Raporlar modülündeki kapsamla sınırlıdır.
 */
trait WithReportFilters
{
    public string $from = '';

    public string $to = '';

    public string $branchId = '';

    public string $warehouseId = '';

    public string $categoryId = '';

    public string $supplierId = '';

    public function mountWithReportFilters(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function updatedWithReportFilters(string $property): void
    {
        if ($property === 'branchId') {
            $this->warehouseId = '';
        }

        if (in_array($property, ['from', 'to', 'branchId', 'warehouseId', 'categoryId', 'supplierId'], true) && method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['branchId', 'warehouseId', 'categoryId', 'supplierId']);
        $this->mountWithReportFilters();
    }

    protected function reportFilters(): ReportFilters
    {
        return ReportFilters::fromArray([
            'from' => $this->from,
            'to' => $this->to,
            'branch_id' => $this->branchId,
            'warehouse_id' => $this->warehouseId,
            'category_id' => $this->categoryId,
            'supplier_id' => $this->supplierId,
        ]);
    }

    /**
     * @return array<int, int>|null
     */
    protected function accessibleBranchIds(): ?array
    {
        return auth()->user()->accessibleBranchIds(Module::Reports);
    }

    /**
     * @return array{branches: Collection, warehouses: Collection, categories: Collection, suppliers: Collection}
     */
    protected function filterOptions(): array
    {
        $accessible = $this->accessibleBranchIds();

        return [
            'branches' => Branch::when($accessible !== null, fn ($query) => $query->whereIn('id', $accessible))->orderBy('name')->get(),
            'warehouses' => Warehouse::whereHas('branch')
                ->inBranches($accessible)
                ->when($this->branchId, fn ($query, $value) => $query->where('branch_id', $value))
                ->with('branch')
                ->orderBy('name')
                ->get(),
            'categories' => Category::orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->get(),
        ];
    }
}
