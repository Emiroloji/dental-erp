<?php

use App\Domain\Platform\Exceptions\PlatformAccountException;
use App\Domain\Platform\Services\PlatformAccountService;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Platform Sahibi hesapları (Aşama 23): yeni hesap açma, geçici şifre
 * yenileme, pasife alma/aktifleştirme. Hiçbir organizasyon verisi yok.
 */
new #[Layout('layouts::platform')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $email = '';

    /** Geçici şifre yalnızca bu ekranda bir kez gösterilir. */
    public ?array $issued = null;

    public function openForm(): void
    {
        $this->reset(['name', 'email', 'issued']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(PlatformAccountService $accounts): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ], [
            'email.unique' => 'Bu e-posta ile kayıtlı bir kullanıcı zaten var.',
        ]);

        $result = $accounts->create($validated, auth()->user());

        $this->showForm = false;
        $this->issued = ['title' => "{$result['user']->name} hesabı açıldı.", 'email' => $result['user']->email, 'password' => $result['password']];
    }

    public function resetPassword(int $userId, PlatformAccountService $accounts): void
    {
        $account = $this->findAccount($userId);
        $password = $accounts->resetPassword($account, auth()->user());

        $this->issued = ['title' => "{$account->name} için geçici şifre yenilendi.", 'email' => $account->email, 'password' => $password];
    }

    public function deactivate(int $userId, PlatformAccountService $accounts): void
    {
        try {
            $accounts->deactivate($this->findAccount($userId), auth()->user());
        } catch (PlatformAccountException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', 'Hesap pasife alındı; panele giriş yapamaz.');
    }

    public function activate(int $userId, PlatformAccountService $accounts): void
    {
        $accounts->activate($this->findAccount($userId), auth()->user());

        session()->flash('status', 'Hesap aktifleştirildi.');
    }

    public function dismissIssued(): void
    {
        $this->issued = null;
    }

    private function findAccount(int $userId): User
    {
        try {
            return User::where('role', User::ROLE_PLATFORM_OWNER)->findOrFail($userId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    public function with(): array
    {
        return [
            'accounts' => User::where('role', User::ROLE_PLATFORM_OWNER)->orderBy('name')->get(),
        ];
    }
};
?>

<div>
    <div class="mb-8 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Platform Hesapları</h1>
            <p class="text-[14px] text-ink-muted mt-1">Platform Yönetici Paneli'ne erişen hesaplar. Bu hesaplar hiçbir kliniğin stok verisini görmez.</p>
        </div>
        <button wire:click="openForm" class="mt-4 sm:mt-0 inline-flex items-center gap-1.5 bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Yeni Hesap
        </button>
    </div>

    @if ($issued)
        <div class="mb-6 rounded-lg border border-brand-500/30 bg-brand-100 px-5 py-4 text-[14px]">
            <p class="font-medium text-ink">{{ $issued['title'] }}</p>
            <p class="mt-1 text-ink-muted">Giriş bilgileri e-postayla gönderildi. Geçici şifre yalnızca şimdi görünür; ilk girişte değiştirilmesi istenecek.</p>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 font-mono text-[13px]">
                <div>E-posta: {{ $issued['email'] }}</div>
                <div>Geçici şifre: <span class="font-medium text-ink">{{ $issued['password'] }}</span></div>
            </div>
            <button wire:click="dismissIssued" class="mt-3 text-[13px] text-ink-muted hover:text-ink hover:underline">Kapat</button>
        </div>
    @endif

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md bg-status-critical-bg border border-status-critical/20 text-status-critical text-[13px] px-4 py-3">{{ session('error') }}</div>
    @endif

    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-5 py-3 font-medium">Ad Soyad</th>
                    <th class="px-5 py-3 font-medium">E-posta</th>
                    <th class="px-5 py-3 font-medium">Durum</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($accounts as $account)
                    <tr wire:key="account-{{ $account->id }}">
                        <td class="px-5 py-3">{{ $account->name }}</td>
                        <td class="px-5 py-3 text-ink-muted">{{ $account->email }}</td>
                        <td class="px-5 py-3">
                            <span @class([
                                'inline-flex items-center px-2 py-0.5 rounded text-[12px]',
                                'bg-status-good-bg text-status-good' => $account->isActive(),
                                'bg-line text-ink-muted' => ! $account->isActive(),
                            ])>{{ $account->isActive() ? 'Aktif' : 'Pasif' }}</span>
                            @if ($account->must_change_password)
                                <span class="ml-1 text-[12px] text-ink-muted">· geçici şifre</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap space-x-3 text-[13px]">
                            @if ($account->is(auth()->user()))
                                <span class="text-ink-muted">Siz</span>
                            @else
                                <button wire:click="resetPassword({{ $account->id }})" wire:confirm="{{ $account->name }} için yeni geçici şifre üretilsin mi? Mevcut şifresi geçersiz olur." class="text-ink-muted hover:text-ink hover:underline">Şifre Yenile</button>
                                @if ($account->isActive())
                                    <button wire:click="deactivate({{ $account->id }})" wire:confirm="{{ $account->name }} pasife alınsın mı? Panele giriş yapamaz." class="text-status-critical hover:underline">Pasife Al</button>
                                @else
                                    <button wire:click="activate({{ $account->id }})" class="text-brand-600 hover:underline">Aktifleştir</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <x-modal :show="$showForm" title="Yeni Platform Sahibi Hesabı" on-close="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
            </div>
            <p class="text-[12px] text-ink-muted">Geçici şifre otomatik üretilir ve e-postayla gönderilir. Yeni hesap tüm platform yetkilerine sahip olur.</p>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Hesabı Aç</button>
                <button type="button" wire:click="closeForm" class="text-[14px] text-ink-muted hover:text-ink">Vazgeç</button>
            </div>
        </form>
    </x-modal>
</div>
