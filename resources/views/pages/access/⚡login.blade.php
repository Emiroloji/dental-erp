<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(): void
    {
        $credentials = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'Girilen bilgiler kayıtlarımızla eşleşmiyor.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->isActive()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Bu kullanıcı pasif durumda, giriş yapamaz.',
            ]);
        }

        // Pasif organizasyon: erişim tamamen engellenir. Salt-okunur organizasyon
        // giriş yapar, yalnızca yazma işlemleri kapalıdır (Aşama 18).
        if ($user->organization && ! $user->organization->status->allowsLogin()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Organizasyonunuz pasif durumda, sisteme erişim engellendi.',
            ]);
        }

        session()->regenerate();

        $this->redirect(match (true) {
            $user->must_change_password => route('password.change'),
            $user->isPlatformOwner() => route('platform.dashboard'),
            default => route('dashboard'),
        });
    }
};
?>

<div class="min-h-screen lg:flex">
    <div class="hidden lg:flex lg:w-[44%] bg-panel-900 text-white flex-col justify-between px-14 py-14 relative overflow-hidden">
        <div class="absolute inset-0 opacity-[0.07]" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 22px 22px;"></div>

        <div class="relative flex items-center gap-2.5">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" class="text-brand-400 shrink-0">
                <path d="M12 3c-2.2 0-3.4 1.1-4.4 1.1-1.3 0-2.4-.9-3.6-.6C2.4 4 2 5.6 2 7.2c0 3 1.4 6.9 2.5 9.3.8 1.7 1.5 3.5 2.8 3.5 1.2 0 1.4-.8 1.9-2.4.4-1.3.7-2.9 1.8-2.9s1.4 1.6 1.8 2.9c.5 1.6.7 2.4 1.9 2.4 1.3 0 2-1.8 2.8-3.5C18.6 14.1 20 10.2 20 7.2c0-1.6-.4-3.2-2-3.7-1.2-.3-2.3.6-3.6.6-1 0-2.2-1.1-4.4-1.1Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
            </svg>
            <span class="font-medium tracking-tight">Dental ERP</span>
        </div>

        <div class="relative max-w-sm">
            <p class="text-[26px] leading-snug font-medium tracking-tight text-white">
                Şubeler, depolar ve lotlar arasında stoğunuzun tek doğru sayısı.
            </p>
            <p class="mt-4 text-[14px] text-white/55 leading-relaxed">
                Her giriş, çıkış ve transfer kayıt altında; hangi lotun ne zaman geldiğini,
                ne zaman tükeneceğini her an bilin.
            </p>
        </div>

        <p class="relative text-[13px] text-white/35">© {{ now()->year }} Dental ERP</p>
    </div>

    <div class="flex-1 flex items-center justify-center px-6 py-16 bg-canvas">
        <form wire:submit="login" class="w-full max-w-[360px]">
            <div class="lg:hidden flex items-center gap-2 mb-10">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" class="text-brand-500 shrink-0">
                    <path d="M12 3c-2.2 0-3.4 1.1-4.4 1.1-1.3 0-2.4-.9-3.6-.6C2.4 4 2 5.6 2 7.2c0 3 1.4 6.9 2.5 9.3.8 1.7 1.5 3.5 2.8 3.5 1.2 0 1.4-.8 1.9-2.4.4-1.3.7-2.9 1.8-2.9s1.4 1.6 1.8 2.9c.5 1.6.7 2.4 1.9 2.4 1.3 0 2-1.8 2.8-3.5C18.6 14.1 20 10.2 20 7.2c0-1.6-.4-3.2-2-3.7-1.2-.3-2.3.6-3.6.6-1 0-2.2-1.1-4.4-1.1Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                </svg>
                <span class="font-medium tracking-tight text-ink">Dental ERP</span>
            </div>

            <h1 class="text-[20px] font-medium tracking-tight text-ink">Giriş yap</h1>
            <p class="text-[14px] text-ink-muted mt-1.5 mb-8">Kliniğinizin stok panelini görüntülemek için oturum açın.</p>

            @if (session('status'))
                <p class="mb-6 rounded-md bg-status-good-bg text-status-good text-[13px] px-3 py-2.5">{{ session('status') }}</p>
            @endif

            <div class="space-y-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">E-posta</label>
                    <input type="email" wire:model="email" autofocus
                        class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('email') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Şifre</label>
                    <input type="password" wire:model="password"
                        class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('password') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
                    <a href="{{ route('password.request') }}" class="inline-block mt-2 text-[13px] text-ink-muted hover:text-ink">Şifremi unuttum</a>
                </div>
            </div>

            <button type="submit" class="w-full mt-7 bg-panel-900 text-white rounded-md py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                Giriş yap
            </button>

            <p class="mt-6 text-[13px] text-ink-muted">
                Hesabınız yok mu? Klinik yöneticiniz sizin için bir hesap oluşturur.
            </p>
        </form>
    </div>
</div>
