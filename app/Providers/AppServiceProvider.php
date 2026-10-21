<?php

namespace App\Providers;

use App\Contracts\SettingsService as SettingsServiceContract;
use App\Services\DatabaseSettingsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SettingsServiceContract::class, DatabaseSettingsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
