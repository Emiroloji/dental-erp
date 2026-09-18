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
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Organizasyonunuz pasif durumda, sisteme erişim engellendi.']);
        }

        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
