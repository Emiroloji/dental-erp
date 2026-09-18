<?php

use App\Domain\Organization\Exceptions\LocationRuleException;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Services\WarehouseService;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public string $branchFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $branch_id = '';

    public string $name = '';

    public string $description = '';

    /** @var array<int, string> */
    public array $managerIds = [];

    public function create(): void
    {
        Gate::authorize('warehouses.manage');

        $this->resetForm();
        $this->branch_id = $this->branchFilter;
        $this->showForm = true;
    }

    public function edit(int $warehouseId): void
    {
        Gate::authorize('warehouses.manage');

        $warehouse = $this->findWarehouse($warehouseId);

        $this->resetForm();
        $this->editingId = $warehouse->id;
        $this->branch_id = (string) $warehouse->branch_id;
        $this->name = $warehouse->name;
        $this->description = (string) $warehouse->description;
        $this->managerIds = array_map('strval', $warehouse->managers->modelKeys());
        $this->showForm = true;
    }

    public function updatedBranchId(): void
    {
        $this->managerIds = [];
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function save(WarehouseService $warehouses): void
    {
        Gate::authorize('warehouses.manage');

        $validated = $this->validate([
            'branch_id' => [
                Rule::requiredIf($this->editingId === null),
                Rule::exists('branches', 'id')
                    ->where('organization_id', auth()->user()->organization_id)
                    ->where('status', 'active'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'managerIds' => ['array'],
            'managerIds.*' => [Rule::exists('users', 'id')->where('organization_id', auth()->user()->organization_id)],
        ], [
            'branch_id.exists' => 'Seçilen şube bulunamadı veya pasif; pasif şubeye depo eklenemez.',
        ]);

        $attributes = ['name' => $validated['name'], 'description' => $validated['description'] ?: null];
        $managerIds = array_map('intval', $validated['managerIds'] ?? []);

        try {
            if ($this->editingId) {
                $warehouses->update($this->findWarehouse($this->editingId), $attributes, $managerIds);
                session()->flash('status', 'Depo güncellendi.');
            } else {
                $warehouses->create(Branch::findOrFail($validated['branch_id']), $attributes, $managerIds);
                session()->flash('status', 'Depo oluşturuldu.');
            }
        } catch (LocationRuleException $e) {
            $this->addError('managerIds', $e->getMessage());

            return;
        }

        $this->closeForm();
    }

    public function makeDefault(int $warehouseId, WarehouseService $warehouses): void
    {
        $this->runRule(fn () => $warehouses->makeDefault($this->findWarehouse($warehouseId)), 'Varsayılan depo değiştirildi.');
    }

    public function deactivate(int $warehouseId, WarehouseService $warehouses): void
    {
        $this->runRule(fn () => $warehouses->deactivate($this->findWarehouse($warehouseId)), 'Depo pasifleştirildi.');
    }

    public function activate(int $warehouseId, WarehouseService $warehouses): void
    {
        $this->runRule(fn () => $warehouses->activate($this->findWarehouse($warehouseId)), 'Depo aktifleştirildi.');
    }

    private function runRule(Closure $action, string $success): void
    {
        Gate::authorize('warehouses.manage');

        try {
            $action();
        } catch (LocationRuleException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', $success);
    }

    private function findWarehouse(int $warehouseId): Warehouse
    {
        try {
            // Warehouse'un kendi organizasyon scope'u yok; kapsamlı Branch ilişkisi üzerinden bulunur.
            return Warehouse::whereHas('branch')->findOrFail($warehouseId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'branch_id', 'name', 'description', 'managerIds']);
        $this->resetValidation();
    }

    public function with(WarehouseService $warehouses): array
    {
        $formBranch = filled($this->branch_id) ? Branch::find($this->branch_id) : null;

        // Sorumlu adayları: seçili şubede stok erişimi olan aktif kullanıcılar.
        $candidates = $formBranch
            ? User::where('organization_id', auth()->user()->organization_id)
                ->where('status', 'active')
                ->with(['branch', 'permissions.branches'])
                ->orderBy('name')
                ->get()
                ->filter(fn (User $user) => $warehouses->canManage($user, $formBranch))
            : collect();

        return [
            'warehouses' => Warehouse::whereHas('branch')
                ->when($this->branchFilter, fn ($query) => $query->where('branch_id', $this->branchFilter))
                ->with(['branch', 'managers'])
                ->withSum(['lots as stock_quantity' => fn ($query) => $query->where('quantity', '>', 0)], 'quantity')
                ->join('branches', 'warehouses.branch_id', '=', 'branches.id')
                ->orderBy('branches.name')
                ->orderByDesc('warehouses.is_default')
                ->orderBy('warehouses.name')
                ->select('warehouses.*')
                ->get(),
            'branches' => Branch::orderBy('name')->get(),
            'candidates' => $candidates,
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Depolar</h1>
            <p class="text-[14px] text-ink-muted mt-1">Şubelerin altındaki stok alanlarını ve depo sorumlularını yönet.</p>
        </div>
        <button wire:click="create" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Yeni Depo
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

    <div class="mb-4">
        <select wire:model.live="branchFilter" class="border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <option value="">Tüm Şubeler</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-hidden">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Depo</th>
                    <th class="px-5 py-3 font-medium">Şube</th>
                    <th class="px-5 py-3 font-medium">Sorumlular</th>
                    <th class="px-5 py-3 font-medium text-right">Stok Miktarı</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                    <th class="px-5 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($warehouses as $warehouse)
                    <tr wire:key="warehouse-{{ $warehouse->id }}">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2">
                                {{ $warehouse->name }}
                                @if ($warehouse->is_default)
                                    <span class="inline-flex px-1.5 py-0.5 rounded text-[11px] bg-brand-100 text-brand-600">Varsayılan</span>
                                @endif
                            </div>
                            @if ($warehouse->description)
                                <div class="text-[12px] text-ink-muted">{{ $warehouse->description }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-ink-muted">{{ $warehouse->branch->name }}</td>
                        <td class="px-5 py-3 text-ink-muted text-[13px]">{{ $warehouse->managers->pluck('name')->join(', ') ?: '—' }}</td>
                        <td class="px-5 py-3 text-right tabular-nums">{{ Number::format((float) $warehouse->stock_quantity, precision: 2) }}</td>
                        <td class="px-5 py-3">
                            <span @class([
                                'inline-flex items-center px-2 py-0.5 rounded text-[12px]',
                                'bg-status-good-bg text-status-good' => $warehouse->status === 'active',
                                'bg-line text-ink-muted' => $warehouse->status !== 'active',
                            ])>
                                {{ $warehouse->status === 'active' ? 'Aktif' : 'Pasif' }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap space-x-3">
                            <button wire:click="edit({{ $warehouse->id }})" class="text-[13px] text-ink-muted hover:text-ink hover:underline">Düzenle</button>
                            @if ($warehouse->status === 'active')
                                @unless ($warehouse->is_default)
                                    <button wire:click="makeDefault({{ $warehouse->id }})" class="text-[13px] text-brand-600 hover:underline">Varsayılan Yap</button>
                                    <button wire:click="deactivate({{ $warehouse->id }})" wire:confirm="Bu depoyu pasifleştirmek istediğine emin misin? Pasif depoda stok işlemi yapılamaz." class="text-[13px] text-status-critical hover:underline">
                                        Pasifleştir
                                    </button>
                                @endunless
                            @else
                                <button wire:click="activate({{ $warehouse->id }})" class="text-[13px] text-brand-600 hover:underline">Aktifleştir</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-ink-muted text-[13px]">Depo bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <x-modal :show="$showForm" :title="$editingId ? 'Depoyu Düzenle' : 'Yeni Depo'" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Şube</label>
                    <select wire:model.live="branch_id" @disabled($editingId) class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 disabled:bg-canvas disabled:text-ink-muted">
                        <option value="">Şube seçin</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}{{ $branch->status !== 'active' ? ' (pasif)' : '' }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Depo Adı</label>
                    <input type="text" wire:model="name" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Açıklama</label>
                <input type="text" wire:model="description" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('description') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Depo Sorumluları</label>
                @if ($candidates->isEmpty())
                    <p class="text-[13px] text-ink-muted">{{ filled($branch_id) ? 'Bu şubede stok yetkisi olan aktif kullanıcı yok.' : 'Önce şube seçin.' }}</p>
                @else
                    <div class="border border-line rounded-md p-3 space-y-1.5 max-h-48 overflow-y-auto">
                        @foreach ($candidates as $candidate)
                            <label class="flex items-center gap-2 text-[13px]" wire:key="candidate-{{ $candidate->id }}">
                                <input type="checkbox" wire:model="managerIds" value="{{ $candidate->id }}" class="accent-brand-500 w-4 h-4">
                                {{ $candidate->name }}
                                <span class="text-ink-muted">· {{ $candidate->isAdmin() ? 'Yönetici' : ($candidate->branch?->name ?? '—') }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="text-[12px] text-ink-muted mt-1">Yalnızca bu şubede stok yetkisi olan kullanıcılar sorumlu atanabilir. Bir kişi birden fazla depodan sorumlu olabilir.</p>
                @endif
                @error('managerIds') <span class="text-status-critical text-[12px] block mt-1">{{ $message }}</span> @enderror
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
