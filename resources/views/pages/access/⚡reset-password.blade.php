<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public string $token = '';

    #[Url]
    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    /**
     * Aşama 28: e-postadaki bağlantıdan gelen tek seferlik token ile yeni şifre
     * belirlenir. Token Password broker tarafından doğrulanır ve kullanıldıktan
     * sonra silinir; geçici şifre işareti de kalkar (kullanıcı kendi şifresini
     * belirlemiş olur).
     */
    public function save(): void
    {
        $this->validate(
            [
                'email' => ['required', 'email'],
                'password' => ['required', 'confirmed', PasswordRule::min(8)],
            ],
            [
                'email.required' => 'E-posta adresinizi girin.',
                'email.email' => 'Geçerli bir e-posta adresi girin.',
                'password.required' => 'Yeni şifrenizi girin.',
                'password.confirmed' => 'Şifre tekrarı eşleşmiyor.',
                'password.min' => 'Şifre en az 8 karakter olmalı.',
            ],
        );

        $user = User::where('email', $this->email)->first();

        if ($user && ! $user->canResetPassword()) {
            throw ValidationException::withMessages([
                'email' => 'Bu hesap şu anda sisteme giriş yapamıyor. Klinik yöneticinizle görüşün.',
            ]);
        }

        $status = Password::reset([
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
            'token' => $this->token,
        ], function (User $user, string $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => match ($status) {
                    Password::INVALID_TOKEN => 'Bu sıfırlama bağlantısı geçersiz veya süresi dolmuş. Yeni bir bağlantı isteyin.',
                    Password::INVALID_USER => 'Bu e-posta ile bir hesap bulunamadı.',
                    Password::RESET_THROTTLED => 'Çok sık deneme yapıldı. Lütfen biraz sonra tekrar deneyin.',
                    default => 'Şifre sıfırlanamadı.',
                },
            ]);
        }

        session()->flash('status', 'Şifreniz güncellendi. Yeni şifrenizle giriş yapabilirsiniz.');

        $this->redirect(route('login'));
    }
};
?>

<div class="min-h-screen flex items-center justify-center px-6 py-16 bg-canvas">
    <form wire:submit="save" class="w-full max-w-[380px]">
        <h1 class="text-[20px] font-medium tracking-tight text-ink">Yeni şifre belirleyin</h1>
        <p class="text-[14px] text-ink-muted mt-1.5 mb-8">
            Bağlantı yalnızca bir kez kullanılabilir. Yeni şifrenizi belirledikten sonra giriş ekranına yönlendirileceksiniz.
        </p>

        <div class="space-y-4">
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">E-posta</label>
                <input type="email" wire:model="email"
                    class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('email') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Yeni şifre (en az 8 karakter)</label>
                <input type="password" wire:model="password" autofocus
                    class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
                @error('password') <p class="text-status-critical text-[12px] mt-1.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[13px] text-ink-muted mb-1.5">Yeni şifre (tekrar)</label>
                <input type="password" wire:model="password_confirmation"
                    class="w-full border border-line rounded-md px-3 py-2.5 text-[14px] bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            </div>
        </div>

        <button type="submit" class="w-full mt-7 bg-panel-900 text-white rounded-md py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
            Şifreyi Kaydet
        </button>

        <a href="{{ route('login') }}" class="block text-center mt-3 text-[13px] text-ink-muted hover:text-ink">Giriş ekranına dön</a>
    </form>
</div>
