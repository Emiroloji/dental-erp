<?php

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Domain\Platform\Services\OrganizationAdminService;
use App\Domain\Platform\Services\PlanLimitService;
use App\Domain\Platform\Support\Plan;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::platform')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $planFilter = '';

    public bool $showForm = false;

    public string $name = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $address = '';

    public string $plan = 'starter';

    public string $admin_name = '';

    public string $admin_email = '';

    /** Yeni açılan hesabın bilgisi — geçici şifre yalnızca bu ekranda bir kez gösterilir. */
    public ?array $created = null;

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'planFilter'], true)) {
            $this->resetPage();
        }
    }

    public function openForm(): void
    {
        $this->reset(['name', 'contact_email', 'contact_phone', 'address', 'plan', 'admin_name', 'admin_email', 'created']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(OrganizationAdminService $organizations): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'plan' => ['required', Rule::enum(Plan::class)],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ], [
            'admin_email.unique' => 'Bu e-posta ile kayıtlı bir kullanıcı zaten var.',
        ]);

        $result = $organizations->create([
            ...$validated,
            'contact_email' => $validated['contact_email'] ?: null,
            'contact_phone' => $validated['contact_phone'] ?: null,
            'address' => $validated['address'] ?: null,
            'plan' => Plan::from($validated['plan']),
        ], auth()->user());

        $this->showForm = false;
        $this->created = [
            'id' => $result['organization']->id,
            'name' => $result['organization']->name,
            'email' => $result['admin']->email,
            'password' => $result['password'],
        ];
    }

    public function dismissCreated(): void
    {
        $this->created = null;
    }

    public function with(PlanLimitService $limits): array
    {
        $organizations = Organization::query()
            ->when($this->search, fn ($query, $value) => $query->whereLike('name', "%{$value}%"))
            ->when($this->statusFilter, fn ($query, $value) => $query->where('status', $value))
            ->when($this->planFilter, fn ($query, $value) => $query->where('plan', $value))
            ->orderBy('name')
            ->paginate(20);

        $organizations->setCollection($organizations->getCollection()->map(fn (Organization $organization) => [
            'organization' => $organization,
            'usage' => $limits->usage($organization),
            'limits' => $organization->limits(),
        ]));

        return [
            'rows' => $organizations,
            'statuses' => OrganizationStatus::cases(),
            'plans' => Plan::cases(),
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Organizasyonlar</h1>
            <p class="text-[14px] text-ink-muted mt-1">Müşteri hesapları (hastane/klinik), paketleri ve durumları.</p>
        </div>
        <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Yeni Organizasyon
        </button>
    </div>

    @if ($created)
        <div class="mb-6 rounded-lg border border-brand-500/30 bg-brand-100 px-5 py-4 text-[14px]">
            <p class="font-medium text-ink">"{{ $created['name'] }}" açıldı.</p>
            <p class="mt-1 text-ink-muted">Ana Klinik Sahibi'ne giriş bilgileri e-postayla gönderildi. Geçici şifre yalnızca şimdi görünür; ilk girişte değiştirilmesi istenecek.</p>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 font-mono text-[13px]">
                <div>E-posta: {{ $created['email'] }}</div>
                <div>Geçici şifre: <span class="font-medium text-ink">{{ $created['password'] }}</span></div>
            </div>
            <div class="mt-3 flex gap-4 text-[13px]">
                <a href="{{ route('platform.organizations.show', $created['id']) }}" class="text-brand-600 hover:underline">Organizasyona git</a>
                <button wire:click="dismissCreated" class="text-ink-muted hover:text-ink">Kapat</button>
            </div>
        </div>
    @endif

    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Organizasyon ara" class="flex-1 border border-line rounded-md px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
        <select wire:model.live="statusFilter" class="border border-line rounded-md px-3 py-2 text-[14px]">
            <option value="">Tüm Durumlar</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="planFilter" class="border border-line rounded-md px-3 py-2 text-[14px]">
            <option value="">Tüm Paketler</option>
            @foreach ($plans as $planOption)
                <option value="{{ $planOption->value }}">{{ $planOption->label() }}</option>
            @endforeach
        </select>
    </div>

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Organizasyon</th>
                    <th class="px-5 py-3 font-medium">Paket</th>
                    <th class="px-5 py-3 font-medium text-right">Şube</th>
                    <th class="px-5 py-3 font-medium text-right">Kullanıcı</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $row)
                    <tr wire:key="organization-{{ $row['organization']->id }}">
                        <td class="px-5 py-3">
                            <a href="{{ route('platform.organizations.show', $row['organization']) }}" class="text-brand-600 hover:underline">{{ $row['organization']->name }}</a>
                            <div class="text-[12px] text-ink-muted">{{ $row['organization']->contact_email ?? '—' }} · {{ $row['organization']->created_at->format('d.m.Y') }}</div>
                        </td>
                        <td class="px-5 py-3">{{ $row['organization']->plan->label() }}</td>
                        <td class="px-5 py-3 text-right tabular-nums {{ $row['limits']['branches'] !== null && $row['usage']['branches'] >= $row['limits']['branches'] ? 'text-status-critical' : '' }}">{{ $row['usage']['branches'] }} / {{ $row['limits']['branches'] ?? '∞' }}</td>
                        <td class="px-5 py-3 text-right tabular-nums {{ $row['limits']['users'] !== null && $row['usage']['users'] >= $row['limits']['users'] ? 'text-status-critical' : '' }}">{{ $row['usage']['users'] }} / {{ $row['limits']['users'] ?? '∞' }}</td>
                        <td class="px-5 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] {{ $row['organization']->status->badgeClasses() }}">{{ $row['organization']->status->label() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-8 text-center text-ink-muted text-[13px]">Organizasyon bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($rows->hasPages())
            <div class="px-5 py-3 border-t border-line">{{ $rows->links() }}</div>
        @endif
    </section>

    <x-modal :show="$showForm" title="Yeni Organizasyon" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Hastane / klinik adı</label>
                <input type="text" wire:model="name" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">İletişim e-postası</label>
                    <input type="email" wire:model="contact_email" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('contact_email') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Telefon</label>
                    <input type="text" wire:model="contact_phone" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                </div>
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Adres</label>
                <input type="text" wire:model="address" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Paket</label>
                <select wire:model="plan" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @foreach ($plans as $planOption)
                        <option value="{{ $planOption->value }}">{{ $planOption->label() }} — {{ $planOption->maxBranches() ?? 'sınırsız' }} şube, {{ $planOption->maxUsers() ?? 'sınırsız' }} kullanıcı</option>
                    @endforeach
                </select>
            </div>
            <div class="border-t border-line pt-4">
                <p class="text-[13px] font-medium text-ink mb-3">Ana Klinik Sahibi (Admin)</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Ad soyad</label>
                        <input type="text" wire:model="admin_name" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                        @error('admin_name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-[13px] text-ink-muted mb-1.5">Giriş e-postası</label>
                        <input type="email" wire:model="admin_email" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                        @error('admin_email') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
            <p class="text-[12px] text-ink-muted">"Merkez Şube" ve "Varsayılan Depo" otomatik açılır. Admin'e geçici şifre e-postayla gider; ilk girişte kendi şifresini belirler.</p>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Organizasyonu Aç</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
