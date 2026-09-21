<?php

use App\Domain\Catalog\Exceptions\ProductRuleException;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Support\Gs1;
use App\Domain\Catalog\Support\ProductType;
use App\Domain\Stock\Support\AlertMode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::authenticated')] class extends Component
{
    use WithPagination;

    public bool $showForm = false;

    /** Düzenlenen ürün; null = yeni ürün. */
    #[Locked]
    public ?int $editingId = null;

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

    // Ürün bazlı uyarı eşiği (Aşama 29.1). Boş mod = organizasyon varsayılanı.
    public string $alert_mode = '';

    public string $alert_quantity_low = '';

    public string $alert_quantity_critical = '';

    public string $alert_expiry_low_days = '';

    public string $alert_expiry_critical_days = '';

    public array $conversionRules = [];

    // İlaç ve medikal ürün alanları (Aşama 26).
    public string $gtin = '';

    public string $uts_number = '';

    public string $license_number = '';

    public string $manufacturer = '';

    public string $storage_condition = '';

    public bool $cold_chain = false;

    public string $storage_min_temp = '';

    public string $storage_max_temp = '';

    public bool $is_controlled = false;

    public bool $tracks_serials = false;

    public function openForm(): void
    {
        Gate::authorize('product_management.create');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $productId): void
    {
        Gate::authorize('product_management.update');

        $product = $this->findProduct($productId);

        $this->resetForm();
        $this->editingId = $product->id;
        $this->fill([
            'name' => $product->name,
            'code' => (string) $product->code,
            'barcode' => (string) $product->barcode,
            'category_id' => (string) $product->category_id,
            'supplier_id' => (string) $product->supplier_id,
            'base_unit' => $product->base_unit,
            'purchase_price' => (string) $product->purchase_price,
            'min_stock' => (string) $product->min_stock,
            'max_stock' => (string) $product->max_stock,
            'product_type' => $product->product_type?->value ?? 'consumable',
            'alert_mode' => $product->alert_mode?->value ?? '',
            'alert_quantity_low' => $product->alert_quantity_low === null ? '' : (string) $product->alert_quantity_low,
            'alert_quantity_critical' => $product->alert_quantity_critical === null ? '' : (string) $product->alert_quantity_critical,
            'alert_expiry_low_days' => $product->alert_expiry_low_days === null ? '' : (string) $product->alert_expiry_low_days,
            'alert_expiry_critical_days' => $product->alert_expiry_critical_days === null ? '' : (string) $product->alert_expiry_critical_days,
            'conversionRules' => collect($product->conversion_rules ?? [])->map(fn ($rule) => ['unit' => $rule['unit'], 'factor' => (string) $rule['factor']])->all(),
            'gtin' => (string) $product->gtin,
            'uts_number' => (string) $product->uts_number,
            'license_number' => (string) $product->license_number,
            'manufacturer' => (string) $product->manufacturer,
            'storage_condition' => (string) $product->storage_condition,
            'cold_chain' => $product->cold_chain,
            'storage_min_temp' => $product->storage_min_temp === null ? '' : (string) $product->storage_min_temp,
            'storage_max_temp' => $product->storage_max_temp === null ? '' : (string) $product->storage_max_temp,
            'is_controlled' => $product->is_controlled,
            'tracks_serials' => $product->tracks_serials,
        ]);
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'code', 'barcode', 'category_id', 'supplier_id', 'purchase_price', 'min_stock', 'max_stock', 'conversionRules',
            'alert_mode', 'alert_quantity_low', 'alert_quantity_critical', 'alert_expiry_low_days', 'alert_expiry_critical_days',
            'gtin', 'uts_number', 'license_number', 'manufacturer', 'storage_condition', 'cold_chain', 'storage_min_temp', 'storage_max_temp', 'is_controlled', 'tracks_serials',
        ]);
        $this->base_unit = 'Adet';
        $this->purchase_price = '0';
        $this->min_stock = '0';
        $this->product_type = 'consumable';
        $this->resetValidation();
    }

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
        Gate::authorize($this->editingId ? 'product_management.update' : 'product_management.create');

        $organizationId = auth()->user()->organization_id;
        $unique = fn (string $column) => Rule::unique('products', $column)->where('organization_id', $organizationId)->ignore($this->editingId);

        // Hangi eksenin eşiği zorunlu: seçilen uyarı moduna göre (Aşama 29.1).
        $selectedMode = $this->alert_mode ? AlertMode::tryFrom($this->alert_mode) : null;
        $tracksQuantity = $selectedMode?->tracksQuantity() ?? false;
        $tracksExpiry = $selectedMode?->tracksExpiry() ?? false;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            // Barkod organizasyon içinde tekil: okutulan barkod tek bir ürüne çözülmeli (Aşama 20).
            'barcode' => ['nullable', 'string', 'max:255', $unique('barcode')],
            'category_id' => ['nullable', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'base_unit' => ['required', 'string', 'max:50'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'min_stock' => ['required', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'product_type' => ['required', Rule::in(array_column(ProductType::cases(), 'value'))],
            'alert_mode' => ['nullable', Rule::in(array_column(AlertMode::cases(), 'value'))],
            'alert_quantity_low' => ['nullable', Rule::requiredIf($tracksQuantity), 'numeric', 'min:0'],
            'alert_quantity_critical' => ['nullable', Rule::requiredIf($tracksQuantity), 'numeric', 'min:0', 'lt:alert_quantity_low'],
            'alert_expiry_low_days' => ['nullable', Rule::requiredIf($tracksExpiry), 'integer', 'min:0', 'max:3650'],
            'alert_expiry_critical_days' => ['nullable', Rule::requiredIf($tracksExpiry), 'integer', 'min:0', 'max:3650', 'lt:alert_expiry_low_days'],
            'conversionRules.*.unit' => ['required_with:conversionRules.*.factor', 'nullable', 'string', 'max:50'],
            'conversionRules.*.factor' => ['required_with:conversionRules.*.unit', 'nullable', 'numeric', 'min:0.01'],
            'gtin' => ['nullable', 'string', 'max:20'],
            'uts_number' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'storage_condition' => ['nullable', 'string', 'max:255'],
            'cold_chain' => ['boolean'],
            'storage_min_temp' => ['nullable', 'required_if:cold_chain,true', 'numeric', 'between:-100,100'],
            'storage_max_temp' => ['nullable', 'required_if:cold_chain,true', 'numeric', 'between:-100,100', 'gte:storage_min_temp'],
            'is_controlled' => ['boolean'],
            'tracks_serials' => ['boolean'],
        ], [
            'alert_quantity_low.required' => 'Miktar eşiği seçildiğinde sarı miktar girilmeli.',
            'alert_quantity_critical.required' => 'Miktar eşiği seçildiğinde kırmızı miktar girilmeli.',
            'alert_quantity_critical.lt' => 'Kırmızı miktar sarı miktardan küçük olmalı.',
            'alert_expiry_low_days.required' => 'SKT eşiği seçildiğinde sarı gün sayısı girilmeli.',
            'alert_expiry_critical_days.required' => 'SKT eşiği seçildiğinde kırmızı gün sayısı girilmeli.',
            'alert_expiry_critical_days.lt' => 'Kırmızı gün sayısı sarı gün sayısından küçük olmalı (daha az gün = daha kritik).',
            'storage_min_temp.required_if' => 'Soğuk zincir ürününde en düşük saklama sıcaklığı girilmeli.',
            'storage_max_temp.required_if' => 'Soğuk zincir ürününde en yüksek saklama sıcaklığı girilmeli.',
            'storage_max_temp.gte' => 'En yüksek sıcaklık en düşükten küçük olamaz.',
        ]);

        $gtin = null;
        if (filled($validated['gtin'])) {
            $gtin = Gs1::normalizeGtin($validated['gtin']);

            if ($gtin === null) {
                $this->addError('gtin', 'Geçerli bir GTIN değil (8, 12, 13 veya 14 hane, kontrol hanesi doğru olmalı).');

                return;
            }

            if (Product::where('gtin', $gtin)->whereKeyNot($this->editingId)->exists()) {
                $this->addError('gtin', 'Bu GTIN başka bir üründe kayıtlı.');

                return;
            }
        }

        $conversionRules = collect($validated['conversionRules'] ?? [])
            ->filter(fn ($rule) => filled($rule['unit']) && filled($rule['factor']))
            ->map(fn ($rule) => ['unit' => $rule['unit'], 'factor' => (float) $rule['factor']])
            ->values()
            ->all();

        // Seri takipli ürün birim birim izlenir; alternatif birim (Kutu vb.) bu ürünlerde kullanılmaz.
        if ($validated['tracks_serials'] && $conversionRules !== []) {
            $this->addError('tracks_serials', 'Seri takipli üründe birim dönüşümü tanımlanamaz; her birim ayrı seri numarasıyla izlenir.');

            return;
        }

        $attributes = [
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
            // Mod seçilmezse tüm eşikler boşalır: ürün varsayılan eşiğe döner.
            // Seçilen modun kapsamadığı eksenin eşikleri de temizlenir.
            'alert_mode' => $selectedMode?->value,
            'alert_quantity_low' => $tracksQuantity ? (float) $validated['alert_quantity_low'] : null,
            'alert_quantity_critical' => $tracksQuantity ? (float) $validated['alert_quantity_critical'] : null,
            'alert_expiry_low_days' => $tracksExpiry ? (int) $validated['alert_expiry_low_days'] : null,
            'alert_expiry_critical_days' => $tracksExpiry ? (int) $validated['alert_expiry_critical_days'] : null,
            'gtin' => $gtin,
            'uts_number' => $validated['uts_number'] ?: null,
            'license_number' => $validated['license_number'] ?: null,
            'manufacturer' => $validated['manufacturer'] ?: null,
            'storage_condition' => $validated['storage_condition'] ?: null,
            'cold_chain' => $validated['cold_chain'],
            'storage_min_temp' => $validated['cold_chain'] && filled($validated['storage_min_temp']) ? (float) $validated['storage_min_temp'] : null,
            'storage_max_temp' => $validated['cold_chain'] && filled($validated['storage_max_temp']) ? (float) $validated['storage_max_temp'] : null,
            'is_controlled' => $validated['is_controlled'],
            'tracks_serials' => $validated['tracks_serials'],
        ];

        try {
            $this->editingId
                ? $productService->update($this->findProduct($this->editingId), $attributes)
                : $productService->create($attributes);
        } catch (ProductRuleException $e) {
            $this->addError('tracks_serials', $e->getMessage());

            return;
        }

        $message = $this->editingId ? 'Ürün güncellendi.' : 'Ürün oluşturuldu.';
        $this->closeForm();
        session()->flash('status', $message);
    }

    public function deactivate(Product $product, ProductService $productService): void
    {
        Gate::authorize('product_management.delete');

        $productService->deactivate($product);

        session()->flash('status', 'Ürün pasifleştirildi.');
    }

    private function findProduct(int $productId): Product
    {
        try {
            return Product::findOrFail($productId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    public function with(): array
    {
        $products = Product::query()
            ->with(['category', 'supplier'])
            ->when($this->search, fn ($query) => $query->where(function ($query) {
                $query->whereLike('name', "%{$this->search}%")
                    ->orWhereLike('code', "%{$this->search}%")
                    ->orWhereLike('barcode', "%{$this->search}%")
                    ->orWhereLike('gtin', "%{$this->search}%");
            }))
            ->when($this->categoryFilter, fn ($query) => $query->where('category_id', $this->categoryFilter))
            ->orderBy('name')
            ->paginate(10);

        return [
            'products' => $products,
            'categories' => Category::where('status', 'active')->orderBy('name')->get(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(),
            'productTypes' => ProductType::cases(),
            'alertModes' => AlertMode::cases(),
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
        @can('product_management.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Ürün
            </button>
        @endcan
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

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
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
                        <td class="px-5 py-3">
                            {{ $product->name }}
                            <x-product-flags :product="$product" />
                            <div class="text-[12px] text-ink-muted mt-0.5">{{ $product->alertRuleLabel() }}</div>
                        </td>
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
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            @can('product_management.update')
                                <button wire:click="edit({{ $product->id }})" class="text-[13px] text-ink-muted hover:text-ink hover:underline mr-3">Düzenle</button>
                            @endcan
                            <a href="{{ route('labels.product', $product->id) }}" target="_blank" class="text-[13px] text-brand-600 hover:underline mr-3">Etiket</a>
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

    <x-modal :show="$showForm" :title="$editingId ? 'Ürünü Düzenle' : 'Yeni Ürün'" on-close="closeForm">
        <form wire:submit="save" class="space-y-7">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
                    <input type="text" wire:model="name" autofocus class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
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
                <h3 class="text-[13px] font-medium text-ink">Uyarı Eşiği</h3>
                <p class="text-[12px] text-ink-muted mb-3">Bu ürün ne zaman sarıya, ne zaman kırmızıya düşsün. Miktara göre, son kullanma tarihine göre ya da ikisine birden bakabilir. Boş bırakılırsa varsayılan eşik (son {{ (int) config('stock.levels.low_quantity_threshold') }} {{ $base_unit ?: 'Adet' }} sarı / son {{ (int) config('stock.levels.critical_quantity_threshold') }} {{ $base_unit ?: 'Adet' }} kırmızı) geçerli olur.</p>

                @php($selectedMode = $alert_mode ? \App\Domain\Stock\Support\AlertMode::tryFrom($alert_mode) : null)

                <div class="sm:w-1/3">
                    <label class="block text-[13px] text-ink-muted mb-1.5">Uyarı Modu</label>
                    <select wire:model.live="alert_mode" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        <option value="">Varsayılan eşik</option>
                        @foreach ($alertModes as $mode)
                            <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                        @endforeach
                    </select>
                    @error('alert_mode') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>

                @if ($selectedMode)
                    <p class="text-[12px] text-ink-muted mt-2">{{ $selectedMode->description() }} Stoğun tükenmesi ve SKT'si geçmiş lot her modda kırmızıdır.</p>
                @endif

                @if ($selectedMode?->tracksQuantity())
                    <div class="mt-4 rounded-md border border-line px-4 py-3">
                        <p class="text-[13px] text-ink mb-2.5">Miktar eşiği <span class="text-ink-muted">— kalan stok {{ $base_unit ?: 'Adet' }} sayısı</span></p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[13px] text-ink-muted mb-1.5">Sarı: altına inince ({{ $base_unit ?: 'Adet' }})</label>
                                <input type="number" step="0.01" min="0" wire:model="alert_quantity_low" placeholder="ör. 60" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                                @error('alert_quantity_low') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-[13px] text-ink-muted mb-1.5">Kırmızı: altına inince ({{ $base_unit ?: 'Adet' }})</label>
                                <input type="number" step="0.01" min="0" wire:model="alert_quantity_critical" placeholder="ör. 30" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                                @error('alert_quantity_critical') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>
                @endif

                @if ($selectedMode?->tracksExpiry())
                    <div class="mt-3 rounded-md border border-line px-4 py-3">
                        <p class="text-[13px] text-ink mb-2.5">SKT eşiği <span class="text-ink-muted">— son kullanma tarihine kalan gün</span></p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[13px] text-ink-muted mb-1.5">Sarı: kaç gün kala</label>
                                <input type="number" min="0" wire:model="alert_expiry_low_days" placeholder="ör. 50" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                                @error('alert_expiry_low_days') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-[13px] text-ink-muted mb-1.5">Kırmızı: kaç gün kala</label>
                                <input type="number" min="0" wire:model="alert_expiry_critical_days" placeholder="ör. 30" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                                @error('alert_expiry_critical_days') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>
                @endif
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

            <div>
                <h3 class="text-[13px] font-medium text-ink">Mevzuat ve Takip</h3>
                <p class="text-[12px] text-ink-muted mb-3">İlaç ve medikal ürünler için ÜTS, ruhsat, saklama koşulu, soğuk zincir, kontrollü ürün ve seri takibi.</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">GTIN (birincil barkod)</label>
                        <input type="text" wire:model="gtin" inputmode="numeric" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 font-mono">
                        @error('gtin') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">ÜTS Ürün No</label>
                        <input type="text" wire:model="uts_number" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 font-mono">
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Ruhsat No</label>
                        <input type="text" wire:model="license_number" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Üretici</label>
                        <input type="text" wire:model="manufacturer" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-[13px] text-ink-muted mb-1.5">Saklama Koşulu</label>
                        <input type="text" wire:model="storage_condition" placeholder="Ör. Kuru yerde, ışıktan uzak" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    <label class="flex items-start gap-2 text-[13px]">
                        <input type="checkbox" wire:model.live="cold_chain" class="accent-brand-500 w-4 h-4 mt-0.5">
                        <span><span class="text-ink">Soğuk zincir</span> <span class="text-ink-muted">— girişte ölçülen sıcaklık zorunlu, aralık dışı giriş engellenir ya da gerekçeyle kabul edilir.</span></span>
                    </label>
                    @if ($cold_chain)
                        <div class="flex flex-wrap items-center gap-2 pl-6 text-[13px]">
                            <input type="number" step="0.1" wire:model="storage_min_temp" placeholder="En düşük" class="w-28 border border-line rounded-md px-3 py-2 tabular-nums">
                            <span class="text-ink-muted">–</span>
                            <input type="number" step="0.1" wire:model="storage_max_temp" placeholder="En yüksek" class="w-28 border border-line rounded-md px-3 py-2 tabular-nums">
                            <span class="text-ink-muted">°C</span>
                        </div>
                        @error('storage_min_temp') <span class="text-status-critical text-[12px] block pl-6">{{ $message }}</span> @enderror
                        @error('storage_max_temp') <span class="text-status-critical text-[12px] block pl-6">{{ $message }}</span> @enderror
                    @endif
                    <label class="flex items-start gap-2 text-[13px]">
                        <input type="checkbox" wire:model="is_controlled" class="accent-brand-500 w-4 h-4 mt-0.5">
                        <span><span class="text-ink">Kontrollü ürün</span> <span class="text-ink-muted">— her çıkışta açıklama zorunlu, hareketleri Kontrollü Ürün Defteri'nde listelenir.</span></span>
                    </label>
                    <label class="flex items-start gap-2 text-[13px]">
                        <input type="checkbox" wire:model="tracks_serials" class="accent-brand-500 w-4 h-4 mt-0.5">
                        <span><span class="text-ink">Seri numarası takibi</span> <span class="text-ink-muted">— her birim ayrı seri numarasıyla girilir ve çıkar (ör. implant). Stok varken değiştirilemez.</span></span>
                    </label>
                    @error('tracks_serials') <span class="text-status-critical text-[12px] block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    Ürünü Kaydet
                </button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">
                    Vazgeç
                </button>
            </div>
        </form>
    </x-modal>
</div>
