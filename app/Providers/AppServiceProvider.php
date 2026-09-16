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
use Opcodes\LogViewer\Facades\LogViewer;

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
            // Realtime (Reverb) for the panel. Pusher + Echo, then our init.
            Js::make('pusher', 'https://js.pusher.com/8.2/pusher.min.js'),
            Js::make('laravel-echo', 'https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js'),
            Js::make('echo-reverb', asset('js/echo-reverb.js')),
        ]);

        FilamentAsset::registerScriptData([
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port', 8080),
                'scheme' => config('broadcasting.connections.reverb.options.scheme', 'http'),
            ],
        ]);

        // Restrict the log viewer (/log-viewer) to admin users.
        LogViewer::auth(fn ($request): bool => (bool) optional($request->user())->is_admin);
    }
}
