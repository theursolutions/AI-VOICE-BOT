<?php

namespace Msd\MetaChannels;

use Illuminate\Support\ServiceProvider;
use Msd\MetaChannels\Services\GraphClient;

class MetaChannelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/meta.php', 'meta');

        $this->app->bind(GraphClient::class, fn () => new GraphClient());
        $this->app->singleton(MetaManager::class);
    }

    public function boot(): void
    {
        // Webhook routes (no middleware group → no CSRF, which is correct
        // for Meta-signed callbacks). Paths include the /api prefix.
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // channel_connections table lives with the package so it travels
        // with it. Same filename as before → already-migrated DBs are
        // untouched.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/meta.php' => function_exists('config_path')
                ? config_path('meta.php')
                : base_path('config/meta.php'),
        ], 'meta-config');
    }
}
