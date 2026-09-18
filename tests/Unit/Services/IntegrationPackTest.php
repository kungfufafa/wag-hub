<?php

namespace Tests\Unit\Services;

use App\Models\ClientApplication;
use App\Services\IntegrationPack;
use Tests\TestCase;

class IntegrationPackTest extends TestCase
{
    public function test_cesa_env_uses_rekrutmen_engine_url_and_disables_local_node(): void
    {
        $application = new ClientApplication([
            'name' => 'Web CESA',
            'slug' => 'web-cesa',
        ]);

        $env = app(IntegrationPack::class)->envSnippet(
            $application,
            'https://gateway.example.com',
            'wgh_hub_token',
            'wgh_engine_token',
        );

        $this->assertStringContainsString('WAG_URL=https://gateway.example.com', $env);
        $this->assertStringContainsString('WAG_TOKEN=wgh_hub_token', $env);
        $this->assertStringContainsString(
            'REKRUTMEN_WHATSAPP_ENGINE_URL=https://gateway.example.com/engine/t/wgh_engine_token',
            $env,
        );
        $this->assertStringContainsString('REKRUTMEN_WHATSAPP_ENGINE_AUTO_START=false', $env);
        $this->assertStringNotContainsString('WAG_TOKEN=wgh_engine_token', $env);
    }

    public function test_other_apps_get_a_generic_engine_url(): void
    {
        $application = new ClientApplication([
            'name' => 'Web Helpdesk',
            'slug' => 'web-helpdesk',
        ]);

        $env = app(IntegrationPack::class)->envSnippet(
            $application,
            'https://hub.test',
            'wgh_hub',
            'wgh_engine',
        );

        $this->assertStringContainsString('ENGINE_URL=https://hub.test/engine/t/wgh_engine', $env);
        $this->assertStringContainsString('WAG_TOKEN=wgh_hub', $env);
        $this->assertStringNotContainsString('REKRUTMEN_WHATSAPP_ENGINE_URL', $env);
    }
}
