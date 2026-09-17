<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\CategoryService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public string $name = '';

    public function save(CategoryService $categoryService): void
    {
        Gate::authorize('category_management.create');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $categoryService->create($validated);

        $this->reset('name');
        session()->flash('status', 'Kategori oluşturuldu.');
    }

    public function deactivate(Category $category, CategoryService $categoryService): void
    {
        Gate::authorize('category_management.delete');

        $categoryService->deactivate($category);

        session()->flash('status', 'Kategori pasifleştirildi.');
    }

    public function with(): array
    {
        return [
            'categories' => Category::orderBy('name')->get(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Kategoriler</h1>
        <p class="text-[14px] text-ink-muted mt-1">Ürünlerini gruplamak için kullandığın kategori listesi.</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">
            {{ session('status') }}
        </div>
    @endif

    <div class="lg:flex lg:items-start lg:gap-8">
        <section class="flex-1 border border-line rounded-lg bg-surface overflow-hidden">
            <table class="w-full text-[14px]">
                <thead>
                    <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                        <th class="px-5 py-3 font-medium">Ad</th>
                        <th class="px-5 py-3 font-medium">Durum</th>
                        <th class="px-5 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($categories as $category)
                        <tr wire:key="category-{{ $category->id }}">
                            <td class="px-5 py-3">{{ $category->name }}</td>
                            <td class="px-5 py-3">
                                <span @class([
                                    'inline-flex items-center px-2 py-0.5 rounded text-[12px]',
                                    'bg-status-good-bg text-status-good' => $category->status === 'active',
                                    'bg-line text-ink-muted' => $category->status !== 'active',
                                ])>
                                    {{ $category->status === 'active' ? 'Aktif' : 'Pasif' }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                @can('category_management.delete')
                                    @if ($category->status === 'active')
                                        <button wire:click="deactivate({{ $category->id }})" wire:confirm="Bu kategoriyi pasifleştirmek istediğine emin misin?" class="text-[13px] text-status-critical hover:underline">
                                            Pasifleştir
                                        </button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-5 py-8 text-center text-ink-muted text-[13px]">Henüz kategori eklenmedi.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        @can('category_management.create')
            <section class="mt-6 lg:mt-0 lg:w-80 shrink-0 border border-line rounded-lg bg-surface p-5">
                <h2 class="text-[14px] font-medium text-ink mb-4">Yeni Kategori</h2>
                <form wire:submit="save" class="space-y-3">
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
                        <input type="text" wire:model="name" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                        @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit" class="w-full bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                        Ekle
                    </button>
                </form>
            </section>
        @endcan
    </div>
</div>
