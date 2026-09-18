<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

new class extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * proje.md Bölüm 2: geçici şifreyle açılan hesap ilk girişte şifresini değiştirir.
     * Kullanıcı istediği zaman da bu ekrandan şifresini değiştirebilir.
     */
    public function save(): void
    {
        $user = auth()->user();

        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8), 'different:current_password'],
        ], [
            'current_password.current_password' => 'Mevcut şifre hatalı.',
            'password.different' => 'Yeni şifre geçici şifreden farklı olmalı.',
        ]);

        $user->update(['password' => Hash::make($this->password), 'must_change_password' => false]);

        session()->flash('status', 'Şifreniz güncellendi.');

        $this->redirect($user->isPlatformOwner() ? route('platform.dashboard') : route('dashboard'));
    }
};
?>

<div class="min-h-screen flex items-center justify-center px-6 py-16 bg-canvas">
    <form wire:submit="save" class="w-full max-w-[380px]">
        <h1 class="text-[20px] font-medium tracking-tight text-ink">Şifrenizi belirleyin</h1>
        <p class="text-[14px] text-ink-muted mt-1.5 mb-8">
            @if (auth()->user()->must_change_password)
                Hesabınız geçici bir şifreyle açıldı. Devam etmeden önce kendi şifrenizi belirleyin.
            @else
                Şifrenizi değiştirin.
            @endif
        </p>

        <div class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Mevcut (geçici) şifre</label>
                <input type="password" wire:model="current_password" autofocus class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('current_password') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Yeni şifre (en az 8 karakter)</label>
                <input type="password" wire:model="password" class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('password') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Yeni şifre (tekrar)</label>
                <input type="password" wire:model="password_confirmation" class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            </div>
        </div>

        <button type="submit" class="w-full mt-7 bg-panel-900 text-white rounded-md py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Şifreyi Kaydet</button>

        <button type="button" onclick="document.getElementById('password-logout-form').requestSubmit()" class="w-full mt-3 text-[13px] text-ink-muted hover:text-ink">Çıkış yap</button>
    </form>
    <form id="password-logout-form" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
</div>
