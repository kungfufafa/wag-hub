<?php

namespace App\Providers;

use App\Events\ProviderHealthChanged;
use App\Notifications\Channels\TelegramChannel;
use App\Services\Alerts\SendProviderHealthAlerts;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
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
        Event::listen(ProviderHealthChanged::class, SendProviderHealthAlerts::class);

        Notification::extend('telegram', fn ($app) => new TelegramChannel);
    }
}
