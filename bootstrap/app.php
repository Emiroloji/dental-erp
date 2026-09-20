<?php

use App\Domain\Platform\Contracts\ErrorReporter;
use App\Domain\Platform\Support\ExceptionReporting;
use App\Http\Controllers\Platform\HealthController;
use App\Http\Middleware\EnsurePlatformOwner;
use App\Http\Middleware\EnsureTenantAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            /*
             * Aşama 28: dışarıdan uptime izleme için sağlık kontrolü.
             * Bilerek "web" middleware grubunun dışında: oturum sürücüsü
             * Redis olduğu için cache/Redis çöktüğünde session middleware'i
             * isteği daha kontrolcüye ulaşmadan patlatırdı — oysa bu uç
             * noktanın asıl işi tam da o anda cevap verebilmek.
             */
            Route::get('/health', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => EnsureTenantAccess::class,
            'platform' => EnsurePlatformOwner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Aşama 28: sunucu hataları (5xx) hata izleme sağlayıcısına gönderilir.
        // Sağlayıcı arayüz arkasındadır (SENTRY_DSN varsa Sentry, yoksa log).
        // Laravel'in kendi log kaydı ayrıca yazılmaya devam eder.
        $exceptions->report(function (Throwable $exception): void {
            if (ExceptionReporting::shouldReport($exception)) {
                app(ErrorReporter::class)->report($exception, ExceptionReporting::context());
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
