<?php

namespace Tests\Feature\Admin;

use App\Providers\AppServiceProvider;
use Filament\Support\Assets\AssetManager;
use Filament\Support\Facades\FilamentAsset;
use Tests\TestCase;

class RealtimeAssetsTest extends TestCase
{
    public function test_echo_is_not_loaded_when_reverb_has_no_key(): void
    {
        $scripts = $this->registeredScripts(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => null]);

        $this->assertContains('flow-builder', $scripts);
        $this->assertNotContains('laravel-echo', $scripts);
        $this->assertNotContains('echo-reverb', $scripts);
        $this->assertNotContains('pusher', $scripts);
    }

    public function test_echo_is_not_loaded_when_broadcasting_is_not_reverb(): void
    {
        $scripts = $this->registeredScripts(['broadcasting.default' => 'log', 'broadcasting.connections.reverb.key' => 'qa-key']);

        $this->assertNotContains('laravel-echo', $scripts);
    }

    public function test_echo_is_loaded_when_reverb_is_configured(): void
    {
        $scripts = $this->registeredScripts(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'qa-key']);

        $this->assertContains('pusher', $scripts);
        $this->assertContains('laravel-echo', $scripts);
        $this->assertContains('echo-reverb', $scripts);
    }

    /**
     * Boot the app service provider against a fresh asset registry with the given config.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function registeredScripts(array $config): array
    {
        config($config);
        $this->app->instance(AssetManager::class, new AssetManager);
        FilamentAsset::clearResolvedInstance(AssetManager::class);

        (new AppServiceProvider($this->app))->boot();

        return collect(FilamentAsset::getScripts())->map->getId()->values()->all();
    }
}
