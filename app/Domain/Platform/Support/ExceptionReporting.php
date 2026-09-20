<?php

namespace App\Domain\Platform\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Aşama 28: hata izlemeye neyin gideceğine ve hangi bağlamla gideceğine
 * karar verir. Amaç 500'leri görmek; doğrulama hatası, yetkisiz erişim,
 * 404 gibi "beklenen" durumlar gürültüdür, gönderilmez.
 */
class ExceptionReporting
{
    private const IGNORED = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        RecordsNotFoundException::class,
        TokenMismatchException::class,
    ];

    public static function shouldReport(Throwable $exception): bool
    {
        foreach (self::IGNORED as $ignored) {
            if ($exception instanceof $ignored) {
                return false;
            }
        }

        // 404/403/419 gibi HTTP hataları kullanıcı kaynaklıdır; yalnızca
        // sunucu tarafı (5xx) raporlanır.
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode() >= 500;
        }

        return true;
    }

    /**
     * Hatanın kimin, hangi ekranda başına geldiği. Form girdileri bilinçli
     * olarak gönderilmez — şifre, hasta/stok verisi dışarı çıkmasın.
     *
     * @return array<string, mixed>
     */
    public static function context(): array
    {
        $context = ['environment' => app()->environment()];

        if (app()->runningInConsole()) {
            $context['source'] = 'console';

            return $context;
        }

        $request = request();

        $context['url'] = $request->fullUrl();
        $context['method'] = $request->method();
        $context['route'] = $request->route()?->getName();

        if ($user = auth()->user()) {
            $context['user_id'] = $user->getAuthIdentifier();
            $context['user_email'] = $user->email;
            $context['organization_id'] = $user->organization_id;
        }

        return $context;
    }
}
