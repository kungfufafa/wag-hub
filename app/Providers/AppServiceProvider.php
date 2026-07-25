<?php

namespace App\Providers;

use App\Events\ProviderHealthChanged;
use App\Listeners\SendProviderHealthAlerts;
use Illuminate\Support\Facades\Event;
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
    }
}
