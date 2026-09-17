<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Support\ProductType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
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

<div class="min-h-screen bg-gray-100">
    <nav class="bg-white shadow px-6 py-4 flex items-center justify-between">
        <a href="{{ route('dashboard') }}" class="font-semibold text-gray-800">Dental ERP</a>
        <a href="{{ route('dashboard') }}" class="text-sm text-gray-600">Kontrol Paneline Dön</a>
    </nav>

    <main class="max-w-5xl mx-auto p-6 space-y-8">
        <h1 class="text-2xl font-semibold text-gray-800">Ürünler</h1>

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded px-4 py-3">
                {{ session('status') }}
            </div>
        @endif

        <section class="bg-white rounded shadow p-4 flex flex-col sm:flex-row gap-3">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Ad, kod veya barkod ara..." class="flex-1 border rounded px-3 py-2 text-sm">
            <select wire:model.live="categoryFilter" class="border rounded px-3 py-2 text-sm">
                <option value="">Tüm Kategoriler</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </section>

        <section class="bg-white rounded shadow">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 border-b">
                    <tr>
                        <th class="px-4 py-3">Ad</th>
                        <th class="px-4 py-3">Kod</th>
                        <th class="px-4 py-3">Kategori</th>
                        <th class="px-4 py-3">Tedarikçi</th>
                        <th class="px-4 py-3">Birim</th>
                        <th class="px-4 py-3">Durum</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($products as $product)
                        <tr wire:key="product-{{ $product->id }}">
                            <td class="px-4 py-3">{{ $product->name }}</td>
                            <td class="px-4 py-3">{{ $product->code }}</td>
                            <td class="px-4 py-3">{{ $product->category?->name }}</td>
                            <td class="px-4 py-3">{{ $product->supplier?->name }}</td>
                            <td class="px-4 py-3">{{ $product->base_unit }}</td>
                            <td class="px-4 py-3">{{ $product->status === 'active' ? 'Aktif' : 'Pasif' }}</td>
                            <td class="px-4 py-3 text-right">
                                @can('product_management.delete')
                                    @if ($product->status === 'active')
                                        <button wire:click="deactivate({{ $product->id }})" wire:confirm="Bu ürünü pasifleştirmek istediğine emin misin?" class="text-sm text-red-600">
                                            Pasifleştir
                                        </button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-gray-400">Kayıt bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="p-4">
                {{ $products->links() }}
            </div>
        </section>

        @can('product_management.create')
            <section class="bg-white rounded shadow p-6 space-y-6">
                <h2 class="text-lg font-semibold text-gray-800">Yeni Ürün</h2>

                <form wire:submit="save" class="space-y-6">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Ad</label>
                            <input type="text" wire:model="name" class="mt-1 w-full border rounded px-3 py-2">
                            @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Ürün Kodu</label>
                            <input type="text" wire:model="code" class="mt-1 w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Barkod</label>
                            <input type="text" wire:model="barcode" class="mt-1 w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Kategori</label>
                            <select wire:model="category_id" class="mt-1 w-full border rounded px-3 py-2">
                                <option value="">Seçiniz</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Tedarikçi</label>
                            <select wire:model="supplier_id" class="mt-1 w-full border rounded px-3 py-2">
                                <option value="">Seçiniz</option>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Ürün Tipi</label>
                            <select wire:model="product_type" class="mt-1 w-full border rounded px-3 py-2">
                                @foreach ($productTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Ana Birim</label>
                            <input type="text" wire:model="base_unit" class="mt-1 w-full border rounded px-3 py-2">
                            @error('base_unit') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Alış Fiyatı</label>
                            <input type="number" step="0.01" wire:model="purchase_price" class="mt-1 w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min. Stok</label>
                            <input type="number" wire:model="min_stock" class="mt-1 w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Maks. Stok</label>
                            <input type="number" wire:model="max_stock" class="mt-1 w-full border rounded px-3 py-2">
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold text-gray-700">Birim Dönüşümleri</h3>
                            <button type="button" wire:click="addConversionRule" class="text-sm text-blue-600">+ Alternatif birim ekle</button>
                        </div>
                        <p class="text-xs text-gray-400 mb-2">Örn: 1 Kutu = 50 {{ $base_unit ?: 'Adet' }}</p>

                        @foreach ($conversionRules as $index => $rule)
                            <div class="flex items-center gap-2 mb-2">
                                <input type="text" wire:model="conversionRules.{{ $index }}.unit" placeholder="Birim (ör. Kutu)" class="border rounded px-3 py-2 text-sm flex-1">
                                <span class="text-sm text-gray-500">=</span>
                                <input type="number" step="0.01" wire:model="conversionRules.{{ $index }}.factor" placeholder="Miktar" class="border rounded px-3 py-2 text-sm w-32">
                                <span class="text-sm text-gray-500">{{ $base_unit ?: 'Adet' }}</span>
                                <button type="button" wire:click="removeConversionRule({{ $index }})" class="text-sm text-red-600">Kaldır</button>
                            </div>
                        @endforeach
                    </div>

                    <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2 hover:bg-blue-700">
                        Ürünü Kaydet
                    </button>
                </form>
            </section>
        @endcan
    </main>
</div>
