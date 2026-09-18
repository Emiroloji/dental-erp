<?php

namespace App\Providers;

use App\Domain\Organization\Support\ReadOnlyGuard;
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
        //
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
