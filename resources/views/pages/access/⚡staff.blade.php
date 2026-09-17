<?php

use App\Domain\Access\Services\StaffService;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
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
            'staffMembers' => User::where('role', User::ROLE_STAFF)->get(),
            'branches' => Branch::all(),
            'moduleList' => Module::cases(),
            'scopeList' => PermissionScope::cases(),
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
        <h1 class="text-2xl font-semibold text-gray-800">Personel Yönetimi</h1>

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded px-4 py-3">
                {{ session('status') }}
            </div>
        @endif

        <section class="bg-white rounded shadow">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 border-b">
                    <tr>
                        <th class="px-4 py-3">Ad Soyad</th>
                        <th class="px-4 py-3">E-posta</th>
                        <th class="px-4 py-3">Durum</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($staffMembers as $member)
                        <tr>
                            <td class="px-4 py-3">{{ $member->name }}</td>
                            <td class="px-4 py-3">{{ $member->email }}</td>
                            <td class="px-4 py-3">{{ $member->status }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-gray-400">Henüz personel eklenmedi.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="bg-white rounded shadow p-6 space-y-6">
            <h2 class="text-lg font-semibold text-gray-800">Yeni Personel Ekle</h2>

            <form wire:submit="save" class="space-y-6">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Ad Soyad</label>
                        <input type="text" wire:model="name" class="mt-1 w-full border rounded px-3 py-2">
                        @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">E-posta</label>
                        <input type="email" wire:model="email" class="mt-1 w-full border rounded px-3 py-2">
                        @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Şifre</label>
                        <input type="password" wire:model="password" class="mt-1 w-full border rounded px-3 py-2">
                        @error('password') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Yetkiler</h3>
                    <table class="w-full text-sm border rounded overflow-hidden">
                        <thead class="bg-gray-50 text-left text-gray-500">
                            <tr>
                                <th class="px-4 py-2">Modül</th>
                                <th class="px-4 py-2 text-center">Okuma</th>
                                <th class="px-4 py-2 text-center">Yazma</th>
                                <th class="px-4 py-2 text-center">Silme</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach ($moduleList as $module)
                                <tr>
                                    <td class="px-4 py-2">{{ $module->label() }}</td>
                                    <td class="px-4 py-2 text-center">
                                        <input type="checkbox" wire:model="modules.{{ $module->value }}.read">
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <input type="checkbox" wire:model="modules.{{ $module->value }}.write">
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <input type="checkbox" wire:model="modules.{{ $module->value }}.delete">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="space-y-3">
                    <label class="block text-sm font-medium text-gray-700">Kapsam</label>
                    <select wire:model.live="scope" class="w-full sm:w-64 border rounded px-3 py-2">
                        @foreach ($scopeList as $scopeOption)
                            <option value="{{ $scopeOption->value }}">{{ $scopeOption->label() }}</option>
                        @endforeach
                    </select>
                    @error('scope') <span class="text-red-600 text-sm block">{{ $message }}</span> @enderror

                    @if ($scope === 'selected_branches')
                        <div class="border rounded p-3 space-y-1">
                            @foreach ($branches as $branch)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model="selectedBranches" value="{{ $branch->id }}">
                                    {{ $branch->name }}
                                </label>
                            @endforeach
                        </div>
                        @error('selectedBranches') <span class="text-red-600 text-sm block">{{ $message }}</span> @enderror
                    @endif
                </div>

                <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2 hover:bg-blue-700">
                    Personeli Kaydet
                </button>
            </form>
        </section>
    </main>
</div>
