<?php

namespace App\Providers;

use App\Domain\Assistant\Contracts\QueryInterpreter;
use App\Domain\Assistant\Interpreters\GeminiQueryInterpreter;
use App\Domain\Assistant\Interpreters\UnconfiguredQueryInterpreter;
use App\Domain\Organization\Support\ReadOnlyGuard;
use App\Domain\Platform\Services\DatabaseBackupService;
use App\Http\Middleware\EnsurePlatformOwner;
use App\Http\Middleware\EnsureTenantAccess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Rapor Asistanı (Aşama 25): sağlayıcı arayüz arkasında. Yeni sağlayıcı
        // (ör. Claude) eklemek için QueryInterpreter uygulanır ve buraya eklenir.
        $this->app->bind(QueryInterpreter::class, function () {
            $gemini = config('services.gemini');

            return match (config('assistant.driver')) {
                'gemini' => filled($gemini['key'] ?? null)
                    ? new GeminiQueryInterpreter($gemini['key'], $gemini['model'], $gemini['endpoint'], (int) $gemini['timeout'])
                    : new UnconfiguredQueryInterpreter,
                default => new UnconfiguredQueryInterpreter,
            };
        });

        // Aşama 28: yedekleme servisi aktif veritabanı bağlantısını ve
        // config/backup.php ayarlarını kullanır.
        $this->app->bind(DatabaseBackupService::class, fn () => new DatabaseBackupService(
            config('database.connections.'.config('database.default')),
            config('backup'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ekrandaki tüm sayılar uygulama diliyle biçimlenir (tr: 1.234,50).
        Number::useLocale($this->app->getLocale());

        // Livewire'ın sonraki (update) istekleri de sayfanın route middleware'inden geçer.
        Livewire::addPersistentMiddleware([EnsureTenantAccess::class, EnsurePlatformOwner::class]);

        // Salt-okunur organizasyon (Aşama 18): her Eloquent yazması tek noktadan denetlenir.
        Event::listen(['eloquent.creating: *', 'eloquent.updating: *', 'eloquent.deleting: *'], function (string $event, array $payload) {
            $this->app->make(ReadOnlyGuard::class)->check($payload[0]);
        });
    }
}
