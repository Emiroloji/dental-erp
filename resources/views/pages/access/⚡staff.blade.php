<?php

use App\Domain\Access\Services\StaffService;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $scope = 'own_branch';

    public array $selectedBranches = [];

    public array $modules = [];

    public function mount(): void
    {
        $this->resetModules();
    }

    public function save(StaffService $staffService): void
    {
        Gate::authorize('staff_management.create');

        $organizationId = auth()->user()->organization_id;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'scope' => ['required', Rule::in(array_column(PermissionScope::cases(), 'value'))],
            'selectedBranches' => ['required_if:scope,selected_branches', 'array'],
            'selectedBranches.*' => [
                Rule::exists('branches', 'id')->where('organization_id', $organizationId),
            ],
        ]);

        $staffService->createStaff(
            attributes: [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ],
            modulePermissions: $this->modules,
            scope: PermissionScope::from($validated['scope']),
            branchIds: $validated['selectedBranches'] ?? [],
        );

        $this->reset(['name', 'email', 'password', 'scope', 'selectedBranches']);
        $this->resetModules();

        session()->flash('status', 'Personel oluşturuldu.');
    }

    private function resetModules(): void
    {
        foreach (Module::cases() as $module) {
            $this->modules[$module->value] = ['read' => false, 'write' => false, 'delete' => false];
        }
    }

    public function with(): array
    {
        return [
            'staffMembers' => User::where('organization_id', auth()->user()->organization_id)
                ->where('role', User::ROLE_STAFF)
                ->get(),
            'branches' => Branch::all(),
            'moduleList' => Module::cases(),
            'scopeList' => PermissionScope::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Personel Yönetimi</h1>
        <p class="text-[14px] text-ink-muted mt-1">Modül bazında okuma, yazma ve silme yetkisi tanımlayarak yeni personel ekle.</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">
            {{ session('status') }}
        </div>
    @endif

    <section class="border border-line rounded-lg bg-surface overflow-hidden">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Ad Soyad</th>
                    <th class="px-5 py-3 font-medium">E-posta</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($staffMembers as $member)
                    <tr>
                        <td class="px-5 py-3">{{ $member->name }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $member->email }}</td>
                        <td class="px-5 py-3">
                            <span @class([
                                'inline-flex items-center px-2 py-0.5 rounded text-[12px]',
                                'bg-status-good-bg text-status-good' => $member->status === 'active',
                                'bg-line text-ink-muted' => $member->status !== 'active',
                            ])>
                                {{ $member->status === 'active' ? 'Aktif' : 'Pasif' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-5 py-8 text-center text-ink-muted text-[13px]">Henüz personel eklenmedi.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    @can('staff_management.create')
        <section class="mt-8 border border-line rounded-lg bg-surface p-6 lg:p-7">
            <h2 class="text-[15px] font-medium text-ink mb-5">Yeni Personel</h2>

            <form wire:submit="save" class="space-y-7">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Ad Soyad</label>
                        <input type="text" wire:model="name" class="w-full border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
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
                    <h3 class="text-[13px] font-medium text-ink mb-3">Yetkiler</h3>
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

                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    Personeli Kaydet
                </button>
            </form>
        </section>
    @endcan
</div>
