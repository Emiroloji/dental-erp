<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\CategoryService;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
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

<div class="min-h-screen bg-gray-100">
    <nav class="bg-white shadow px-6 py-4 flex items-center justify-between">
        <a href="{{ route('dashboard') }}" class="font-semibold text-gray-800">Dental ERP</a>
        <a href="{{ route('dashboard') }}" class="text-sm text-gray-600">Kontrol Paneline Dön</a>
    </nav>

    <main class="max-w-3xl mx-auto p-6 space-y-8">
        <h1 class="text-2xl font-semibold text-gray-800">Kategoriler</h1>

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded px-4 py-3">
                {{ session('status') }}
            </div>
        @endif

        <section class="bg-white rounded shadow">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 border-b">
                    <tr>
                        <th class="px-4 py-3">Ad</th>
                        <th class="px-4 py-3">Durum</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($categories as $category)
                        <tr wire:key="category-{{ $category->id }}">
                            <td class="px-4 py-3">{{ $category->name }}</td>
                            <td class="px-4 py-3">{{ $category->status === 'active' ? 'Aktif' : 'Pasif' }}</td>
                            <td class="px-4 py-3 text-right">
                                @can('category_management.delete')
                                    @if ($category->status === 'active')
                                        <button wire:click="deactivate({{ $category->id }})" wire:confirm="Bu kategoriyi pasifleştirmek istediğine emin misin?" class="text-sm text-red-600">
                                            Pasifleştir
                                        </button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-gray-400">Henüz kategori eklenmedi.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        @can('category_management.create')
            <section class="bg-white rounded shadow p-6 space-y-4">
                <h2 class="text-lg font-semibold text-gray-800">Yeni Kategori</h2>
                <form wire:submit="save" class="flex items-end gap-3">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700">Ad</label>
                        <input type="text" wire:model="name" class="mt-1 w-full border rounded px-3 py-2">
                        @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2 hover:bg-blue-700">
                        Ekle
                    </button>
                </form>
            </section>
        @endcan
    </main>
</div>
