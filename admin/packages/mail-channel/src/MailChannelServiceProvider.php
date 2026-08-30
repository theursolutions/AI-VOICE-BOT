<?php

namespace Msd\MailChannel;

use Illuminate\Support\ServiceProvider;

class MailChannelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mail_channel.php', 'mail_channel');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/mail_channel.php' => function_exists('config_path')
                ? config_path('mail_channel.php')
                : base_path('config/mail_channel.php'),
        ], 'mail-channel-config');
    }
}
