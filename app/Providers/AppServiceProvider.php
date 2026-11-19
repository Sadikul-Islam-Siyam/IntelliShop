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

                $url = "http://{$host}:{$port}";

                $event->output->writeln([
                    '',
                    '  <fg=cyan;options=bold>======================================================================</>',
                    "  <fg=green;options=bold>🛒  IntelliShop Super Shop & Decision Support System is running!</>",
                    '  <fg=cyan;options=bold>======================================================================</>',
                    "  <fg=yellow;options=bold>➜</>  Application Link:     <fg=bright-cyan;options=bold,underscore>{$url}</>",
                    "  <fg=yellow;options=bold>➜</>  Login Portal:         <fg=bright-blue;options=underscore>{$url}/login</>",
                    "  <fg=yellow;options=bold>➜</>  Role Dashboard:       <fg=bright-blue;options=underscore>{$url}/dashboard</>",
                    "  <fg=yellow;options=bold>➜</>  POS Terminal:         <fg=bright-blue;options=underscore>{$url}/pos</>",
                    "  <fg=yellow;options=bold>➜</>  Replenishment Orders: <fg=bright-blue;options=underscore>{$url}/reorders</>",
                    "  <fg=yellow;options=bold>➜</>  Stock-Out Risk:       <fg=bright-blue;options=underscore>{$url}/stock-risk</>",
                    "  <fg=yellow;options=bold>➜</>  Demand Forecasting:   <fg=bright-blue;options=underscore>{$url}/forecasting</>",
                    "  <fg=yellow;options=bold>➜</>  Supplier Rankings:    <fg=bright-blue;options=underscore>{$url}/suppliers/ranking</>",
                    "  <fg=yellow;options=bold>➜</>  Promotions Engine:    <fg=bright-blue;options=underscore>{$url}/promotions</>",
                    '  <fg=cyan;options=bold>======================================================================</>',
                    '  <fg=gray>Press Ctrl+C to stop the server</>',
                    '',
                ]);
            }
        });
    }
}
