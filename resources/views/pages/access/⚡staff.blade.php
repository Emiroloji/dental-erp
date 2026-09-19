<?php

use App\Domain\Access\Exceptions\StaffRuleException;
use App\Domain\Access\Services\StaffService;
use App\Domain\Platform\Exceptions\PlanLimitException;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $branch_id = '';

    public string $scope = 'own_branch';

    public array $selectedBranches = [];

    public array $modules = [];

    public function mount(): void
    {
        $this->resetModules();
    }

    public function openForm(): void
    {
        Gate::authorize('staff_management.create');

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['name', 'email', 'password', 'branch_id', 'scope', 'selectedBranches']);
        $this->resetModules();
        $this->resetValidation();
    }

    public function save(StaffService $staffService): void
    {
        Gate::authorize('staff_management.create');

        $organizationId = auth()->user()->organization_id;

        // Şube kuralı hem "organizasyonun şubesi" hem de "yöneticinin kendi
        // kapsamındaki şube" olmalı — kapsamı sınırlı bir personel yöneticisi
        // başka şubeye personel atayamaz.
        $branchRule = Rule::exists('branches', 'id')
            ->where('organization_id', $organizationId)
            ->when($this->branchIds() !== null, fn ($rule) => $rule->whereIn('id', $this->branchIds()));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'branch_id' => ['required', $branchRule],
            'scope' => ['required', Rule::in(array_column(PermissionScope::cases(), 'value')), $this->grantableScopeRule()],
            'selectedBranches' => ['required_if:scope,selected_branches', 'array'],
            'selectedBranches.*' => [$branchRule],
            'modules' => ['array', $this->grantableModulesRule()],
        ]);

        try {
            $staffService->createStaff(
            attributes: [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'branch_id' => (int) $validated['branch_id'],
            ],
            modulePermissions: $this->modules,
            scope: PermissionScope::from($validated['scope']),
            branchIds: $validated['selectedBranches'] ?? [],
            );
        } catch (PlanLimitException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->closeForm();
        session()->flash('status', 'Personel oluşturuldu.');
    }

    public function deactivate(int $userId, StaffService $staffService): void
    {
        Gate::authorize('staff_management.update');

        try {
            $staffService->deactivate($this->findStaff($userId));
        } catch (StaffRuleException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', 'Personel pasife alındı; sisteme giriş yapamaz.');
    }

    public function activate(int $userId, StaffService $staffService): void
    {
        Gate::authorize('staff_management.update');

        try {
            $staffService->activate($this->findStaff($userId));
        } catch (StaffRuleException|PlanLimitException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', 'Personel aktifleştirildi.');
    }

    /**
     * Yalnızca bu organizasyonun ve yöneticinin kapsamındaki şubelerin
     * personeli; kapsam dışı bir kayıt 404 döner.
     */
    private function findStaff(int $userId): User
    {
        try {
            return $this->staffQuery()->findOrFail($userId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function staffQuery()
    {
        return User::where('organization_id', auth()->user()->organization_id)
            ->where('role', User::ROLE_STAFF)
            ->when($this->branchIds() !== null, fn ($query) => $query->whereIn('branch_id', $this->branchIds()));
    }

    private function resetModules(): void
    {
        foreach (Module::cases() as $module) {
            $this->modules[$module->value] = ['read' => false, 'write' => false, 'delete' => false];
        }
    }

    /**
     * Yetki yükseltme engeli: yönetici, kendisinde olmayan bir modül yetkisini
     * (okuma/yazma/silme) başkasına veremez. Admin her yetkiye sahip olduğu için
     * etkilenmez.
     */
    private function grantableModulesRule(): Closure
    {
        return function (string $attribute, mixed $modules, Closure $fail) {
            foreach ((array) $modules as $moduleValue => $abilities) {
                $module = Module::tryFrom((string) $moduleValue);

                foreach ((array) $abilities as $ability => $granted) {
                    if ($granted && ($module === null || ! auth()->user()->canModule($module, (string) $ability))) {
                        $fail('Kendinizde olmayan bir yetkiyi veremezsiniz: '.($module?->label() ?? $moduleValue).'.');

                        return;
                    }
                }
            }
        };
    }

    private function grantableScopeRule(): Closure
    {
        return function (string $attribute, mixed $scope, Closure $fail) {
            if ($scope === PermissionScope::All->value && $this->branchIds() !== null) {
                $fail('Kendi kapsamınız sınırlıyken "Tüm şubeler" kapsamı veremezsiniz.');
            }
        };
    }

    /**
     * @return array<int, int>|null
     */
    private function branchIds(): ?array
    {
        return auth()->user()->accessibleBranchIds(Module::StaffManagement);
    }

    public function with(): array
    {
        return [
            'staffMembers' => $this->staffQuery()
                ->with('branch')
                ->orderBy('name')
                ->get(),
            'branches' => Branch::when($this->branchIds() !== null, fn ($query) => $query->whereIn('id', $this->branchIds()))
                ->where('status', 'active')
                ->orderBy('name')
                ->get(),
            'moduleList' => Module::cases(),
            'scopeList' => PermissionScope::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Personel Yönetimi</h1>
            <p class="text-[14px] text-ink-muted mt-1">Modül bazında okuma, yazma ve silme yetkisi tanımlayarak yeni personel ekle.</p>
        </div>
        @can('staff_management.create')
            <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Yeni Personel
            </button>
        @endcan
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

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Ad Soyad</th>
                    <th class="px-5 py-3 font-medium">E-posta</th>
                    <th class="px-5 py-3 font-medium">Şube</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($staffMembers as $member)
                    <tr>
                        <td class="px-5 py-3">{{ $member->name }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $member->email }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $member->branch?->name ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <span @class([
                                'inline-flex items-center px-2 py-0.5 rounded text-[12px]',
                                'bg-status-good-bg text-status-good' => $member->status === 'active',
                                'bg-line text-ink-muted' => $member->status !== 'active',
                            ])>
                                {{ $member->status === 'active' ? 'Aktif' : 'Pasif' }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            @can('staff_management.update')
                                @if ($member->is(auth()->user()))
                                    <span class="text-[12px] text-ink-muted">Siz</span>
                                @elseif ($member->status === 'active')
                                    <button wire:click="deactivate({{ $member->id }})" wire:confirm="{{ $member->name }} pasife alınsın mı? Pasif personel sisteme giriş yapamaz; geçmiş kayıtları korunur." class="text-[13px] text-status-critical hover:underline">
                                        Pasife Al
                                    </button>
                                @else
                                    <button wire:click="activate({{ $member->id }})" class="text-[13px] text-brand-600 hover:underline">
                                        Aktifleştir
                                    </button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-8 text-center text-ink-muted text-[13px]">Henüz personel eklenmedi.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <x-modal :show="$showForm" title="Yeni Personel" on-close="closeForm">
        <form wire:submit="save" class="space-y-7">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Ad Soyad</label>
                    <input type="text" wire:model="name" autofocus class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">E-posta</label>
                    <input type="email" wire:model="email" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('email') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Şifre</label>
                    <input type="password" wire:model="password" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('password') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Bağlı Olduğu Şube</label>
                <select wire:model="branch_id" class="w-full sm:w-72 border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    <option value="">Şube seçin</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
                <p class="text-[12px] text-ink-muted mt-1">"Sadece kendi şubesi" kapsamı bu şubeye göre uygulanır.</p>
                @error('branch_id') <span class="text-status-critical text-[12px] block mt-1">{{ $message }}</span> @enderror
            </div>

            <div>
                <h3 class="text-[13px] font-medium text-ink mb-3">Yetkiler</h3>
                @error('modules') <span class="text-status-critical text-[12px] block -mt-1 mb-2">{{ $message }}</span> @enderror
                <div class="border border-line rounded-md overflow-hidden">
                    <table class="w-full text-[13px]">
                        <thead>
                            <tr class="text-left text-ink-muted border-b border-line bg-canvas">
                                <th class="px-4 py-2.5 font-medium">Modül</th>
                                <th class="px-4 py-2.5 font-medium text-center w-20">Okuma</th>
                                <th class="px-4 py-2.5 font-medium text-center w-20">Yazma</th>
                                <th class="px-4 py-2.5 font-medium text-center w-20">Silme</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($moduleList as $module)
                                <tr>
                                    <td class="px-4 py-2.5">{{ $module->label() }}</td>
                                    <td class="px-4 py-2.5 text-center">
                                        <input type="checkbox" wire:model="modules.{{ $module->value }}.read" class="accent-brand-500 w-4 h-4">
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <input type="checkbox" wire:model="modules.{{ $module->value }}.write" class="accent-brand-500 w-4 h-4">
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <input type="checkbox" wire:model="modules.{{ $module->value }}.delete" class="accent-brand-500 w-4 h-4">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Kapsam</label>
                <select wire:model.live="scope" class="w-full sm:w-72 border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @foreach ($scopeList as $scopeOption)
                        <option value="{{ $scopeOption->value }}">{{ $scopeOption->label() }}</option>
                    @endforeach
                </select>
                @error('scope') <span class="text-status-critical text-[12px] block mt-1">{{ $message }}</span> @enderror

                @if ($scope === 'selected_branches')
                    <div class="mt-3 border border-line rounded-md p-3 space-y-1.5 max-w-sm">
                        @foreach ($branches as $branch)
                            <label class="flex items-center gap-2 text-[13px]">
                                <input type="checkbox" wire:model="selectedBranches" value="{{ $branch->id }}" class="accent-brand-500 w-4 h-4">
                                {{ $branch->name }}
                            </label>
                        @endforeach
                    </div>
                    @error('selectedBranches') <span class="text-status-critical text-[12px] block mt-1">{{ $message }}</span> @enderror
                @endif
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    Personeli Kaydet
                </button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">
                    Vazgeç
                </button>
            </div>
        </form>
    </x-modal>
</div>
