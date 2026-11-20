<?php

namespace App\Providers;

use App\Contracts\AnalyticsClient as AnalyticsClientContract;
use App\Contracts\SettingsService as SettingsServiceContract;
use App\Models\Sale;
use App\Observers\SaleObserver;
use App\Services\Analytics\HttpAnalyticsClient;
use App\Services\DatabaseSettingsService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SettingsServiceContract::class, DatabaseSettingsService::class);
        $this->app->bind(AnalyticsClientContract::class, HttpAnalyticsClient::class);
        $this->app->bind(\App\Contracts\InventoryService::class, \App\Services\InventoryService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sale::observe(SaleObserver::class);

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (in_array($event->command, ['serve', 'dev'])) {
                $host = env('SERVER_HOST', '127.0.0.1');
                $port = env('SERVER_PORT', '8000');

                try {
                    if ($event->input->hasOption('host') && $event->input->getOption('host')) {
                        $host = (string) $event->input->getOption('host');
                    }
                    if ($event->input->hasOption('port') && $event->input->getOption('port')) {
                        $port = (string) $event->input->getOption('port');
                    }
                } catch (\Throwable $e) {
                    // Fall back to defaults
                }

                // If served on 0.0.0.0 or wildcard/empty (typical in Docker containers),
                // browsers cannot open 0.0.0.0 on macOS/Windows (fails with invalid address).
                // Resolve to localhost for a clickable, working link.
                $browserHost = in_array($host, ['0.0.0.0', '::', '0', '']) ? 'localhost' : $host;
                $url = "http://{$browserHost}:{$port}";

                $event->output->writeln([
                    '',
                    '  <fg=cyan;options=bold>======================================================================</>',
                    "  <fg=green;options=bold>🛒  IntelliShop running on:</> <fg=bright-cyan;options=bold,underscore>{$url}</>",
                    '  <fg=cyan;options=bold>======================================================================</>',
                    '',
                ]);
            }
        });
    }
}
