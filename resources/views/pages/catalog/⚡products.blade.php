<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Support\ProductType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $categoryFilter = '';

    public string $name = '';

    public string $code = '';

    public string $barcode = '';

    public string $category_id = '';

    public string $supplier_id = '';

    public string $base_unit = 'Adet';

    public string $purchase_price = '0';

    public string $min_stock = '0';

    public string $max_stock = '';

    public string $product_type = 'consumable';

    public array $conversionRules = [];

    public function addConversionRule(): void
    {
        $this->conversionRules[] = ['unit' => '', 'factor' => ''];
    }

    public function removeConversionRule(int $index): void
    {
        unset($this->conversionRules[$index]);
        $this->conversionRules = array_values($this->conversionRules);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function save(ProductService $productService): void
    {
        Gate::authorize('product_management.create');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'base_unit' => ['required', 'string', 'max:50'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'min_stock' => ['required', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'product_type' => ['required', Rule::in(array_column(ProductType::cases(), 'value'))],
            'conversionRules.*.unit' => ['required_with:conversionRules.*.factor', 'nullable', 'string', 'max:50'],
            'conversionRules.*.factor' => ['required_with:conversionRules.*.unit', 'nullable', 'numeric', 'min:0.01'],
        ]);

        $conversionRules = collect($validated['conversionRules'] ?? [])
            ->filter(fn ($rule) => filled($rule['unit']) && filled($rule['factor']))
            ->map(fn ($rule) => ['unit' => $rule['unit'], 'factor' => (float) $rule['factor']])
            ->values()
            ->all();

        $productService->create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?: null,
            'barcode' => $validated['barcode'] ?: null,
            'category_id' => $validated['category_id'] ?: null,
            'supplier_id' => $validated['supplier_id'] ?: null,
            'base_unit' => $validated['base_unit'],
            'conversion_rules' => $conversionRules ?: null,
            'purchase_price' => $validated['purchase_price'],
            'min_stock' => $validated['min_stock'],
            'max_stock' => $validated['max_stock'] ?: null,
            'product_type' => $validated['product_type'],
        ]);

        $this->reset(['name', 'code', 'barcode', 'category_id', 'supplier_id', 'purchase_price', 'min_stock', 'max_stock', 'conversionRules']);
        $this->base_unit = 'Adet';
        $this->purchase_price = '0';
        $this->min_stock = '0';
        $this->product_type = 'consumable';

        session()->flash('status', 'Ürün oluşturuldu.');
    }

    public function deactivate(Product $product, ProductService $productService): void
    {
        Gate::authorize('product_management.delete');

        $productService->deactivate($product);

        session()->flash('status', 'Ürün pasifleştirildi.');
    }

    public function with(): array
    {
        $products = Product::query()
            ->with(['category', 'supplier'])
            ->when($this->search, fn ($query) => $query->where(function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%")
                    ->orWhere('barcode', 'like', "%{$this->search}%");
            }))
            ->when($this->categoryFilter, fn ($query) => $query->where('category_id', $this->categoryFilter))
            ->orderBy('name')
            ->paginate(10);

        return [
            'products' => $products,
            'categories' => Category::where('status', 'active')->orderBy('name')->get(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(),
            'productTypes' => ProductType::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Ürünler</h1>
            <p class="text-[14px] text-ink-muted mt-1">Stok kartlarını, birim dönüşümlerini ve tedarikçi eşleşmelerini yönet.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">
            {{ session('status') }}
        </div>
    @endif

    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <div class="relative flex-1">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-muted"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3" stroke-linecap="round"/></svg>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Ad, kod veya barkod ara" class="w-full border border-line rounded-md pl-9 pr-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
        </div>
        <select wire:model.live="categoryFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Kategoriler</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-hidden">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Ad</th>
                    <th class="px-5 py-3 font-medium">Kod</th>
                    <th class="px-5 py-3 font-medium">Kategori</th>
                    <th class="px-5 py-3 font-medium">Tedarikçi</th>
                    <th class="px-5 py-3 font-medium">Birim</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                    <th class="px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($products as $product)
                    <tr wire:key="product-{{ $product->id }}">
                        <td class="px-5 py-3">{{ $product->name }}</td>
                        <td class="px-5 py-3 text-ink-muted font-mono text-[13px]">{{ $product->code }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $product->category?->name }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $product->supplier?->name }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $product->base_unit }}</td>
                        <td class="px-5 py-3">
                            <span @class([
                                'inline-flex items-center px-2 py-0.5 rounded text-[12px]',
                                'bg-status-good-bg text-status-good' => $product->status === 'active',
                                'bg-line text-ink-muted' => $product->status !== 'active',
                            ])>
                                {{ $product->status === 'active' ? 'Aktif' : 'Pasif' }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            @can('product_management.delete')
                                @if ($product->status === 'active')
                                    <button wire:click="deactivate({{ $product->id }})" wire:confirm="Bu ürünü pasifleştirmek istediğine emin misin?" class="text-[13px] text-status-critical hover:underline">
                                        Pasifleştir
                                    </button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-ink-muted text-[13px]">Kayıt bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($products->hasPages())
            <div class="px-5 py-3 border-t border-line">
                {{ $products->links() }}
            </div>
        @endif
    </section>

    @can('product_management.create')
        <section class="mt-8 border border-line rounded-lg bg-surface p-6 lg:p-7">
            <h2 class="text-[15px] font-medium text-ink mb-5">Yeni Ürün</h2>

            <form wire:submit="save" class="space-y-7">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
                        <input type="text" wire:model="name" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Ürün Kodu</label>
                        <input type="text" wire:model="code" class="w-full border border-line rounded-md px-3 py-2 text-[14px] font-mono focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Barkod</label>
                        <input type="text" wire:model="barcode" class="w-full border border-line rounded-md px-3 py-2 text-[14px] font-mono focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Kategori</label>
                        <select wire:model="category_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                            <option value="">Seçiniz</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Tedarikçi</label>
                        <select wire:model="supplier_id" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                            <option value="">Seçiniz</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Ürün Tipi</label>
                        <select wire:model="product_type" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                            @foreach ($productTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Ana Birim</label>
                        <input type="text" wire:model="base_unit" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        @error('base_unit') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Alış Fiyatı</label>
                        <input type="number" step="0.01" wire:model="purchase_price" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Min. Stok</label>
                        <input type="number" wire:model="min_stock" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Maks. Stok</label>
                        <input type="number" wire:model="max_stock" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-[13px] font-medium text-ink">Birim Dönüşümleri</h3>
                        <button type="button" wire:click="addConversionRule" class="text-[13px] text-brand-600 hover:text-brand-500">+ Alternatif birim ekle</button>
                    </div>
                    <p class="text-[12px] text-ink-muted mb-2">Örn: 1 Kutu = 50 {{ $base_unit ?: 'Adet' }}</p>

                    @foreach ($conversionRules as $index => $rule)
                        <div class="flex items-center gap-2 mb-2">
                            <input type="text" wire:model="conversionRules.{{ $index }}.unit" placeholder="Birim (ör. Kutu)" class="border border-line rounded-md px-3 py-2 text-[13px] flex-1 focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                            <span class="text-[13px] text-ink-muted">=</span>
                            <input type="number" step="0.01" wire:model="conversionRules.{{ $index }}.factor" placeholder="Miktar" class="border border-line rounded-md px-3 py-2 text-[13px] w-28 tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                            <span class="text-[13px] text-ink-muted">{{ $base_unit ?: 'Adet' }}</span>
                            <button type="button" wire:click="removeConversionRule({{ $index }})" class="text-[13px] text-status-critical hover:underline">Kaldır</button>
                        </div>
                    @endforeach
                </div>

                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    Ürünü Kaydet
                </button>
            </form>
        </section>
    @endcan
</div>
