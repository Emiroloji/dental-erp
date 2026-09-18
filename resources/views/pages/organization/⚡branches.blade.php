<?php

use App\Domain\Organization\Exceptions\LocationRuleException;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Services\BranchService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $address = '';

    public string $phone = '';

    public function create(): void
    {
        Gate::authorize('branches.manage');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $branchId): void
    {
        Gate::authorize('branches.manage');

        $branch = $this->findBranch($branchId);

        $this->resetForm();
        $this->editingId = $branch->id;
        $this->name = $branch->name;
        $this->address = (string) $branch->address;
        $this->phone = (string) $branch->phone;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function save(BranchService $branches): void
    {
        Gate::authorize('branches.manage');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $attributes = [
            'name' => $validated['name'],
            'address' => $validated['address'] ?: null,
            'phone' => $validated['phone'] ?: null,
        ];

        if ($this->editingId) {
            $branches->update($this->findBranch($this->editingId), $attributes);
            session()->flash('status', 'Şube güncellendi.');
        } else {
            $branches->create($attributes);
            session()->flash('status', 'Şube oluşturuldu; "Varsayılan Depo" otomatik açıldı.');
        }

        $this->closeForm();
    }

    public function deactivate(int $branchId, BranchService $branches): void
    {
        Gate::authorize('branches.manage');

        try {
            $branches->deactivate($this->findBranch($branchId));
        } catch (LocationRuleException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', 'Şube pasifleştirildi.');
    }

    public function activate(int $branchId, BranchService $branches): void
    {
        Gate::authorize('branches.manage');

        $branches->activate($this->findBranch($branchId));

        session()->flash('status', 'Şube aktifleştirildi.');
    }

    private function findBranch(int $branchId): Branch
    {
        try {
            return Branch::findOrFail($branchId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'address', 'phone']);
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'branches' => Branch::withCount(['warehouses', 'users'])->orderBy('name')->get(),
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Şubeler</h1>
            <p class="text-[14px] text-ink-muted mt-1">Hastanenin şubelerini yönet. Her yeni şubeye otomatik bir varsayılan depo açılır.</p>
        </div>
        <button wire:click="create" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Yeni Şube
        </button>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md bg-status-critical-bg border border-status-critical/20 text-status-critical text-[13px] px-4 py-3">
            {{ session('error') }}
        </div>
    @endif

    <section class="border border-line rounded-lg bg-surface overflow-hidden">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Ad</th>
                    <th class="px-5 py-3 font-medium">İletişim</th>
                    <th class="px-5 py-3 font-medium text-right">Depo</th>
                    <th class="px-5 py-3 font-medium text-right">Personel</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                    <th class="px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($branches as $branch)
                    <tr wire:key="branch-{{ $branch->id }}">
                        <td class="px-5 py-3">{{ $branch->name }}</td>
                        <td class="px-5 py-3 text-ink-muted text-[13px]">
                            <div>{{ $branch->address ?? '—' }}</div>
                            @if ($branch->phone)
                                <div>{{ $branch->phone }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ $branch->warehouses_count }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ $branch->users_count }}</td>
                        <td class="px-5 py-3">
                            <span @class([
                                'inline-flex items-center px-2 py-0.5 rounded text-[12px]',
                                'bg-status-good-bg text-status-good' => $branch->status === 'active',
                                'bg-line text-ink-muted' => $branch->status !== 'active',
                            ])>
                                {{ $branch->status === 'active' ? 'Aktif' : 'Pasif' }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap space-x-3">
                            <button wire:click="edit({{ $branch->id }})" class="text-[13px] text-ink-muted hover:text-ink hover:underline">Düzenle</button>
                            @if ($branch->status === 'active')
                                <button wire:click="deactivate({{ $branch->id }})" wire:confirm="Bu şubeyi pasifleştirmek istediğine emin misin? Pasif şubede stok işlemi yapılamaz." class="text-[13px] text-status-critical hover:underline">
                                    Pasifleştir
                                </button>
                            @else
                                <button wire:click="activate({{ $branch->id }})" class="text-[13px] text-brand-600 hover:underline">
                                    Aktifleştir
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-ink-muted text-[13px]">Henüz şube eklenmedi.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <x-modal :show="$showForm" :title="$editingId ? 'Şubeyi Düzenle' : 'Yeni Şube'" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Ad</label>
                <input type="text" wire:model="name" autofocus class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-[13px] text-ink-muted mb-1.5">Adres</label>
                    <input type="text" wire:model="address" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('address') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Telefon</label>
                    <input type="text" wire:model="phone" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('phone') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="flex items-center gap-3 pt-2">
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
