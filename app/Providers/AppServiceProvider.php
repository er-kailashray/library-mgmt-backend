<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
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
        // In production every link the API hands out (photos, the signed checkout page) is https,
        // even behind a proxy that talks plain http to the app.
        if ($this->app->isProduction()) {
            URL::forceHttps();
        }
    }
}
