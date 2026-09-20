<?php

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public string $email = '';

    public bool $sent = false;

    /**
     * Aşama 28: şifremi unuttum. Laravel'in password reset broker'ı kullanılır
     * (tek seferlik token, süreli bağlantı). Bağlantı yalnızca aktif bir
     * kullanıcıya ve girişe izin veren bir organizasyona gönderilir; ancak
     * kullanıcıya dönen mesaj her durumda aynıdır — böylece bu ekran "bu
     * e-posta sistemde var mı?" sorusunun cevabını dışarı sızdırmaz.
     */
    public function sendLink(): void
    {
        $this->validate(
            ['email' => ['required', 'email']],
            [
                'email.required' => 'E-posta adresinizi girin.',
                'email.email' => 'Geçerli bir e-posta adresi girin.',
            ],
        );

        $user = User::where('email', $this->email)->first();

        if ($user?->canResetPassword()) {
            $status = Password::sendResetLink(['email' => $this->email]);

            if ($status === Password::RESET_THROTTLED) {
                throw ValidationException::withMessages([
                    'email' => 'Az önce bir sıfırlama bağlantısı gönderildi. Lütfen bir dakika bekleyip tekrar deneyin.',
                ]);
            }
        }

        $this->sent = true;
    }
};
?>

<div class="min-h-screen flex items-center justify-center px-6 py-16 bg-canvas">
    <div class="w-full max-w-[380px]">
        <h1 class="text-[20px] font-medium tracking-tight text-ink">Şifremi unuttum</h1>

        @if ($sent)
            <p class="text-[14px] text-ink-muted mt-1.5 mb-8">
                Bu e-posta adresine ait bir hesap varsa, şifre sıfırlama bağlantısı gönderildi.
                Bağlantı bir süre sonra geçersiz olur ve yalnızca bir kez kullanılabilir.
            </p>

            <a href="{{ route('login') }}" class="block text-center w-full bg-panel-900 text-white rounded-md py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                Giriş ekranına dön
            </a>
        @else
            <p class="text-[14px] text-ink-muted mt-1.5 mb-8">
                Hesabınızın e-posta adresini girin, şifrenizi yeniden belirlemeniz için bir bağlantı gönderelim.
            </p>

            <form wire:submit="sendLink">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">E-posta</label>
                    <input type="email" wire:model="email" autofocus
                        class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                    @error('email') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="w-full mt-7 bg-panel-900 text-white rounded-md py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                    Sıfırlama bağlantısı gönder
                </button>

                <a href="{{ route('login') }}" class="block text-center mt-3 text-[13px] text-ink-muted hover:text-ink">Giriş ekranına dön</a>
            </form>
        @endif
    </div>
</div>
