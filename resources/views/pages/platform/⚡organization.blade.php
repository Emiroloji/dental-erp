<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Domain\Platform\Models\PlanChangeRequest;
use App\Domain\Platform\Services\OrganizationAdminService;
use App\Domain\Platform\Services\PlanLimitService;
use App\Domain\Platform\Support\Plan;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public int $organizationId;

    public string $name = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $address = '';

    public string $plan = '';

    public string $max_branches = '';

    public string $max_users = '';

    public string $max_storage_mb = '';

    /** Yeniden üretilen geçici şifre — yalnızca bir kez gösterilir. */
    public ?array $resetResult = null;

    public function mount(int $organization): void
    {
        $model = Organization::findOrFail($organization);
        $this->organizationId = $model->id;
        $this->fillForms($model);
    }

    private function fillForms(Organization $organization): void
    {
        $this->name = $organization->name;
        $this->contact_email = (string) $organization->contact_email;
        $this->contact_phone = (string) $organization->contact_phone;
        $this->address = (string) $organization->address;
        $this->plan = $organization->plan->value;
        $this->max_branches = (string) $organization->max_branches;
        $this->max_users = (string) $organization->max_users;
        $this->max_storage_mb = (string) $organization->max_storage_mb;
    }

    private function organization(): Organization
    {
        return Organization::findOrFail($this->organizationId);
    }

    public function saveProfile(OrganizationAdminService $organizations): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $organizations->updateProfile($this->organization(), array_map(fn ($value) => $value === '' ? null : $value, $validated), auth()->user());
        session()->flash('status', 'Organizasyon bilgileri güncellendi.');
    }

    public function savePlan(OrganizationAdminService $organizations): void
    {
        $validated = $this->validate([
            'plan' => ['required', Rule::enum(Plan::class)],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_storage_mb' => ['nullable', 'integer', 'min:1'],
        ]);

        $organizations->applyPlan($this->organization(), Plan::from($validated['plan']), [
            'max_branches' => $validated['max_branches'] === '' || $validated['max_branches'] === null ? null : (int) $validated['max_branches'],
            'max_users' => $validated['max_users'] === '' || $validated['max_users'] === null ? null : (int) $validated['max_users'],
            'max_storage_mb' => $validated['max_storage_mb'] === '' || $validated['max_storage_mb'] === null ? null : (int) $validated['max_storage_mb'],
        ], auth()->user());

        session()->flash('status', 'Paket ve limitler uygulandı.');
    }

    public function setStatus(string $status, OrganizationAdminService $organizations): void
    {
        $organizations->setStatus($this->organization(), OrganizationStatus::from($status), auth()->user());
        session()->flash('status', 'Organizasyon durumu: '.OrganizationStatus::from($status)->label().'.');
    }

    public function resetPassword(int $userId, OrganizationAdminService $organizations): void
    {
        $admin = User::where('organization_id', $this->organizationId)->findOrFail($userId);
        $password = $organizations->resetAdminPassword($admin, auth()->user());
        $this->resetResult = ['email' => $admin->email, 'password' => $password];
    }

    public function with(PlanLimitService $limits): array
    {
        $organization = $this->organization();
        $selectedPlan = Plan::tryFrom($this->plan) ?? $organization->plan;

        return [
            'organization' => $organization,
            'usage' => $limits->usage($organization),
            'limits' => $organization->limits(),
            'overages' => $selectedPlan === $organization->plan ? [] : $limits->overagesFor($organization, $selectedPlan),
            'plans' => Plan::cases(),
            'statuses' => OrganizationStatus::cases(),
            'pendingRequest' => PlanChangeRequest::withoutGlobalScopes()->where('organization_id', $organization->id)->where('status', 'pending')->first(),
            'admins' => User::where('organization_id', $organization->id)->where('role', User::ROLE_ADMIN)->orderBy('name')->get(),
            'history' => AuditLog::withoutGlobalScopes()
                ->where('entity_type', Organization::class)
                ->where('entity_id', $organization->id)
                ->with('actor')
                ->latest('id')
                ->limit(20)
                ->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <a href="{{ route('platform.organizations.index') }}" class="text-[13px] text-ink-muted hover:text-ink">← Organizasyonlar</a>
        <h1 class="mt-2 text-[22px] font-medium tracking-tight text-ink flex items-center gap-3">
            {{ $organization->name }}
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] font-normal {{ $organization->status->badgeClasses() }}">{{ $organization->status->label() }}</span>
        </h1>
        <p class="text-[14px] text-ink-muted mt-1">{{ $organization->plan->label() }} paketi · açılış {{ $organization->created_at->format('d.m.Y') }}</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @if ($pendingRequest)
        <div class="mb-6 rounded-md bg-status-warn-bg border border-status-warn/30 text-status-warn text-[13px] px-4 py-3">
            Bekleyen paket talebi: {{ $pendingRequest->current_plan->label() }} → {{ $pendingRequest->requested_plan->label() }} ({{ $pendingRequest->created_at->format('d.m.Y') }}).
            <a href="{{ route('platform.plan-requests.index') }}" class="underline">Paket Talepleri'nde sonuçlandırın</a>.
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-px bg-line rounded-lg overflow-hidden border border-line mb-8">
        @foreach (['branches' => 'Aktif şube', 'users' => 'Aktif kullanıcı', 'storage_mb' => 'Depolama (MB)'] as $key => $label)
            <div class="bg-surface px-5 py-4">
                <p class="text-[12px] text-ink-muted">{{ $label }}</p>
                <p class="text-[20px] font-medium tabular-nums {{ $limits[$key] !== null && $usage[$key] >= $limits[$key] ? 'text-status-critical' : '' }}">{{ Number::format($usage[$key], maxPrecision: 2) }} <span class="text-[14px] text-ink-muted font-normal">/ {{ $limits[$key] === null ? 'Sınırsız' : Number::format($limits[$key]) }}</span></p>
            </div>
        @endforeach
    </div>

    <section class="mb-8 border border-line rounded-lg bg-surface px-5 py-5">
        <h2 class="text-[15px] font-medium text-ink">Durum</h2>
        <p class="text-[13px] text-ink-muted mt-1 mb-4">Her zaman manuel kararınızdır. Salt-okunur: kullanıcılar giriş yapar ve görür, hiçbir şey ekleyip değiştiremez. Pasif: tüm kullanıcıların erişimi kapanır. Veri hiçbir durumda silinmez.</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($statuses as $status)
                @if ($status !== $organization->status)
                    <button wire:click="setStatus('{{ $status->value }}')" wire:confirm="{{ $organization->name }} &quot;{{ $status->label() }}&quot; durumuna alınsın mı?"
                        class="text-[13px] border rounded-md px-3 py-2 transition-colors {{ $status === \App\Domain\Organization\Support\OrganizationStatus::Passive ? 'border-status-critical/40 text-status-critical hover:bg-status-critical-bg' : 'border-line hover:bg-canvas' }}">
                        {{ $status->label() }} yap
                    </button>
                @endif
            @endforeach
        </div>
    </section>

    <section class="mb-8 border border-line rounded-lg bg-surface px-5 py-5">
        <h2 class="text-[15px] font-medium text-ink">Paket ve limitler</h2>
        <p class="text-[13px] text-ink-muted mt-1 mb-4">Boş bırakılan limit paketin varsayılanını kullanır. Mevcut şube ve kullanıcılar hiçbir zaman silinmez; limit aşılırsa yalnızca yeni ekleme engellenir.</p>
        <form wire:submit="savePlan" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Paket</label>
                    <select wire:model.live="plan" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                        @foreach ($plans as $planOption)
                            <option value="{{ $planOption->value }}">{{ $planOption->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Şube limiti (özel)</label>
                    <input type="number" min="1" wire:model="max_branches" placeholder="{{ \App\Domain\Platform\Support\Plan::tryFrom($plan)?->maxBranches() ?? 'Sınırsız' }}" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('max_branches') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Kullanıcı limiti (özel)</label>
                    <input type="number" min="1" wire:model="max_users" placeholder="{{ \App\Domain\Platform\Support\Plan::tryFrom($plan)?->maxUsers() ?? 'Sınırsız' }}" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('max_users') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Depolama MB (özel)</label>
                    <input type="number" min="1" wire:model="max_storage_mb" placeholder="{{ \App\Domain\Platform\Support\Plan::tryFrom($plan)?->maxStorageMb() ?? 'Sınırsız' }}" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('max_storage_mb') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
            </div>
            @if ($overages !== [])
                <div class="rounded-md bg-status-warn-bg border border-status-warn/30 text-status-warn text-[13px] px-4 py-3">
                    Limit aşımı uyarısı — bu pakette mevcut kullanım limitin üstünde kalır:
                    @foreach ($overages as $key => $overage)
                        {{ ['branches' => 'şube', 'users' => 'kullanıcı', 'storage_mb' => 'depolama (MB)'][$key] }} {{ Number::format($overage['used'], maxPrecision: 2) }}/{{ $overage['limit'] }}@if (! $loop->last), @endif
                    @endforeach.
                    Uygularsanız mevcut kayıtlar korunur, kullanım limitin altına inene kadar yeni ekleme yapılamaz.
                </div>
            @endif
            <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">Paketi Uygula</button>
        </form>
    </section>

    <section class="mb-8 border border-line rounded-lg bg-surface px-5 py-5">
        <h2 class="text-[15px] font-medium text-ink mb-4">Bilgiler</h2>
        <form wire:submit="saveProfile" class="space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Ad</label>
                    <input type="text" wire:model="name" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('name') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">İletişim e-postası</label>
                    <input type="email" wire:model="contact_email" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('contact_email') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Telefon</label>
                    <input type="text" wire:model="contact_phone" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Adres</label>
                    <input type="text" wire:model="address" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                </div>
            </div>
            <button type="submit" class="border border-line rounded-md px-4 py-2 text-[14px] hover:bg-canvas transition-colors">Bilgileri Kaydet</button>
        </form>
    </section>

    <section class="mb-8">
        <h2 class="text-[15px] font-medium text-ink mb-3">Ana Klinik Sahibi hesapları</h2>
        @if ($resetResult)
            <div class="mb-3 rounded-md border border-brand-500/30 bg-brand-100 px-4 py-3 text-[13px] font-mono">
                {{ $resetResult['email'] }} için yeni geçici şifre: <span class="font-medium">{{ $resetResult['password'] }}</span> (e-postayla da gönderildi; yalnızca şimdi görünür)
            </div>
        @endif
        <div class="border border-line rounded-lg bg-surface divide-y divide-line">
            @foreach ($admins as $admin)
                <div class="px-5 py-3 flex items-center justify-between gap-4 text-[14px]" wire:key="admin-{{ $admin->id }}">
                    <div>
                        {{ $admin->name }} <span class="text-ink-muted">· {{ $admin->email }}</span>
                        @if ($admin->must_change_password) <span class="text-[12px] text-status-warn">· geçici şifre, henüz değiştirilmedi</span> @endif
                        @if (! $admin->isActive()) <span class="text-[12px] text-ink-muted">· pasif</span> @endif
                    </div>
                    <button wire:click="resetPassword({{ $admin->id }})" wire:confirm="{{ $admin->email }} için yeni geçici şifre üretilsin mi? Eski şifre geçersiz olur." class="text-[13px] text-brand-600 hover:underline shrink-0">Geçici şifre üret</button>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <h2 class="text-[15px] font-medium text-ink mb-3">Değişiklik geçmişi</h2>
        <ol class="border-l border-line ml-1 space-y-3">
            @forelse ($history as $log)
                <li class="pl-4 relative">
                    <span class="absolute -left-[5px] top-1.5 w-2 h-2 rounded-full bg-brand-500"></span>
                    <div class="text-[13px]">{{ $log->actor?->name ?? 'Sistem' }} · {{ $log->created_at->format('d.m.Y H:i') }}</div>
                    <div class="text-[12px] text-ink-muted">
                        @foreach (($log->after ?? []) as $field => $value)
                            {{ $field }}: {{ is_array($log->before) && array_key_exists($field, $log->before) ? (is_scalar($log->before[$field]) || $log->before[$field] === null ? ($log->before[$field] ?? '—') : '…').' → ' : '' }}{{ is_scalar($value) || $value === null ? ($value ?? '—') : '…' }}@if (! $loop->last); @endif
                        @endforeach
                    </div>
                </li>
            @empty
                <li class="pl-4 text-[13px] text-ink-muted">Kayıt yok.</li>
            @endforelse
        </ol>
    </section>
</div>
