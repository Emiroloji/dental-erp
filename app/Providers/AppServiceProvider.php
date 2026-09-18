<?php

namespace App\Providers;

use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

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
    }
}
