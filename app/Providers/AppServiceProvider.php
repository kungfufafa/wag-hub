<?php

namespace App\Providers;

use App\Events\ProviderHealthChanged;
use App\Notifications\Channels\TelegramChannel;
use App\Services\Alerts\SendProviderHealthAlerts;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
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

        // Load the Flow Builder logic on every panel page so window.flowBuilder
        // is always defined — required for Alpine x-data to work under SPA
        // navigation (the Drawflow lib itself is lazy-loaded on demand).
        FilamentAsset::register([
            Js::make('flow-builder', asset('js/flow-builder.js')),
        ]);
    }
}
