<?php

use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\SupplierService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $contact_person = '';

    public string $phone = '';

    public string $email = '';

    public string $tax_number = '';

    public function openForm(): void
    {
        Gate::authorize('supplier_management.create');

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['name', 'contact_person', 'phone', 'email', 'tax_number']);
        $this->resetValidation();
    }

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

        $this->closeForm();
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

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Tedarikçiler</h1>
            <p class="text-[14px] text-ink-muted mt-1">Sipariş verdiğin firmaların iletişim ve vergi bilgileri.</p>
        </div>
        @can('supplier_management.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Tedarikçi
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">
            {{ session('status') }}
        </div>
    @endif

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Firma</th>
                    <th class="px-5 py-3 font-medium">Yetkili</th>
                    <th class="px-5 py-3 font-medium">İletişim</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                    <th class="px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($suppliers as $supplier)
                    <tr wire:key="supplier-{{ $supplier->id }}">
                        <td class="px-5 py-3">{{ $supplier->name }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $supplier->contact_person }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $supplier->phone }} {{ $supplier->email }}</td>
                        <td class="px-5 py-3">
                            <span @class([
                                'inline-flex items-center px-2 py-0.5 rounded text-[12px]',
                                'bg-status-good-bg text-status-good' => $supplier->status === 'active',
                                'bg-line text-ink-muted' => $supplier->status !== 'active',
                            ])>
                                {{ $supplier->status === 'active' ? 'Aktif' : 'Pasif' }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            @can('supplier_management.delete')
                                @if ($supplier->status === 'active')
                                    <button wire:click="deactivate({{ $supplier->id }})" wire:confirm="Bu tedarikçiyi pasifleştirmek istediğine emin misin?" class="text-[13px] text-status-critical hover:underline">
                                        Pasifleştir
                                    </button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-8 text-center text-ink-muted text-[13px]">Henüz tedarikçi eklenmedi.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <x-modal :show="$showForm" title="Yeni Tedarikçi" on-close="closeForm">
        <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Firma Adı</label>
                <input type="text" wire:model="name" autofocus class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Yetkili Kişi</label>
                <input type="text" wire:model="contact_person" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Telefon</label>
                <input type="text" wire:model="phone" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">E-posta</label>
                <input type="email" wire:model="email" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('email') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Vergi No</label>
                <input type="text" wire:model="tax_number" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            </div>
            <div class="sm:col-span-2 flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    Kaydet
                </button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">
                    Vazgeç
                </button>
            </div>
        </form>
    </x-modal>
</div>
