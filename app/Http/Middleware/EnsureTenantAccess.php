<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant (klinik) ekranlarının kapısı:
 * - Platform Sahibi klinik/stok ekranlarına giremez (kurallar.md Bölüm 4) — kendi paneline yönlenir.
 * - Organizasyonu pasife alınmış kullanıcının açık oturumu da kapatılır (proje.md Bölüm 4).
 * - Geçici şifreyle giriş yapan kullanıcı önce şifresini değiştirir (proje.md Bölüm 2).
 */
class EnsureTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->isPlatformOwner()) {
            return redirect()->route('platform.dashboard');
        }

        if ($user->organization && ! $user->organization->status->allowsLogin()) {
            return $this->logout($request, 'Organizasyonunuz pasif durumda, sisteme erişim engellendi.');
        }

        // Pasife alınan personelin açık oturumu da kapanır (kurallar.md Bölüm 4).
        if (! $user->isActive()) {
            return $this->logout($request, 'Bu kullanıcı pasif durumda, giriş yapamaz.');
        }

        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
