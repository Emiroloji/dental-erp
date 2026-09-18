<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform Yönetici Paneli (/platform/*, mimari.md Bölüm 9) yalnızca Platform
 * Sahibi'ne açıktır. Geçici şifreyle giren Platform Sahibi de önce şifresini değiştirir.
 */
class EnsurePlatformOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isPlatformOwner() && $user->isActive(), 403);

        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
