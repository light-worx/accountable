<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Support\Translation\OverridingLoader;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->extend('translation.loader', fn ($loader) => new OverridingLoader($loader));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
