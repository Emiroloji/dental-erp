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

        if ($user->organization && $user->organization->status !== 'active') {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Organizasyonunuz pasif durumda, sisteme erişim engellendi.',
            ]);
        }

        session()->regenerate();

        $this->redirect(route('dashboard'));
    }
};
?>

<div class="min-h-screen flex items-center justify-center bg-gray-100">
    <form wire:submit="login" class="bg-white p-8 rounded shadow w-full max-w-sm space-y-4">
        <h1 class="text-xl font-semibold text-gray-800">Giriş Yap</h1>

        <div>
            <label class="block text-sm font-medium text-gray-700">E-posta</label>
            <input type="email" wire:model="email" class="mt-1 w-full border rounded px-3 py-2" autofocus>
            @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Şifre</label>
            <input type="password" wire:model="password" class="mt-1 w-full border rounded px-3 py-2">
            @error('password') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <button type="submit" class="w-full bg-blue-600 text-white rounded py-2 hover:bg-blue-700">
            Giriş Yap
        </button>
    </form>
</div>
