<?php

use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\SupplierService;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $contact_person = '';

    public string $phone = '';

    public string $email = '';

    public string $tax_number = '';

    public function save(SupplierService $supplierService): void
    {
        Gate::authorize('supplier_management.create');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
        ]);

        $supplierService->create($validated);

        $this->reset(['name', 'contact_person', 'phone', 'email', 'tax_number']);
        session()->flash('status', 'Tedarikçi oluşturuldu.');
    }

    public function deactivate(Supplier $supplier, SupplierService $supplierService): void
    {
        Gate::authorize('supplier_management.delete');

        $supplierService->deactivate($supplier);

        session()->flash('status', 'Tedarikçi pasifleştirildi.');
    }

    public function with(): array
    {
        return [
            'suppliers' => Supplier::orderBy('name')->get(),
        ];
    }
};
?>

<div class="min-h-screen bg-gray-100">
    <nav class="bg-white shadow px-6 py-4 flex items-center justify-between">
        <a href="{{ route('dashboard') }}" class="font-semibold text-gray-800">Dental ERP</a>
        <a href="{{ route('dashboard') }}" class="text-sm text-gray-600">Kontrol Paneline Dön</a>
    </nav>

    <main class="max-w-4xl mx-auto p-6 space-y-8">
        <h1 class="text-2xl font-semibold text-gray-800">Tedarikçiler</h1>

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded px-4 py-3">
                {{ session('status') }}
            </div>
        @endif

        <section class="bg-white rounded shadow">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 border-b">
                    <tr>
                        <th class="px-4 py-3">Firma</th>
                        <th class="px-4 py-3">Yetkili</th>
                        <th class="px-4 py-3">İletişim</th>
                        <th class="px-4 py-3">Durum</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($suppliers as $supplier)
                        <tr wire:key="supplier-{{ $supplier->id }}">
                            <td class="px-4 py-3">{{ $supplier->name }}</td>
                            <td class="px-4 py-3">{{ $supplier->contact_person }}</td>
                            <td class="px-4 py-3">{{ $supplier->phone }} {{ $supplier->email }}</td>
                            <td class="px-4 py-3">{{ $supplier->status === 'active' ? 'Aktif' : 'Pasif' }}</td>
                            <td class="px-4 py-3 text-right">
                                @can('supplier_management.delete')
                                    @if ($supplier->status === 'active')
                                        <button wire:click="deactivate({{ $supplier->id }})" wire:confirm="Bu tedarikçiyi pasifleştirmek istediğine emin misin?" class="text-sm text-red-600">
                                            Pasifleştir
                                        </button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-gray-400">Henüz tedarikçi eklenmedi.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        @can('supplier_management.create')
            <section class="bg-white rounded shadow p-6 space-y-4">
                <h2 class="text-lg font-semibold text-gray-800">Yeni Tedarikçi</h2>
                <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Firma Adı</label>
                        <input type="text" wire:model="name" class="mt-1 w-full border rounded px-3 py-2">
                        @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Yetkili Kişi</label>
                        <input type="text" wire:model="contact_person" class="mt-1 w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Telefon</label>
                        <input type="text" wire:model="phone" class="mt-1 w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">E-posta</label>
                        <input type="email" wire:model="email" class="mt-1 w-full border rounded px-3 py-2">
                        @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Vergi No</label>
                        <input type="text" wire:model="tax_number" class="mt-1 w-full border rounded px-3 py-2">
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2 hover:bg-blue-700">
                            Ekle
                        </button>
                    </div>
                </form>
            </section>
        @endcan
    </main>
</div>
