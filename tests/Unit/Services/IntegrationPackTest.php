<?php

namespace Tests\Unit\Services;

use App\Models\ClientApplication;
use App\Services\IntegrationPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_cesa_env_separates_bearer_tokens_and_disables_local_node(): void
    {
        $application = new ClientApplication([
            'name' => 'Web CESA',
            'slug' => 'web-cesa',
        ]);

        $env = app(IntegrationPack::class)->envSnippet(
            $application,
            'https://gateway.example.com/',
            'wgh_hub_token',
            'wgh_engine_token',
        );

        $this->assertStringContainsString('WAG_URL=https://gateway.example.com', $env);
        $this->assertStringContainsString('WAG_TOKEN=wgh_hub_token', $env);
        $this->assertStringContainsString(
            'WAG_ENGINE_URL=https://gateway.example.com/api/v1/engine',
            $env,
        );
        $this->assertStringContainsString('REKRUTMEN_WHATSAPP_ENGINE_DRIVER=wag_hub', $env);
        $this->assertStringContainsString('REKRUTMEN_WHATSAPP_ENGINE_URL=${WAG_ENGINE_URL}', $env);
        $this->assertStringContainsString('REKRUTMEN_WHATSAPP_ENGINE_TOKEN=${WAG_ENGINE_TOKEN}', $env);
        $this->assertStringContainsString('WAG_ENGINE_TOKEN=wgh_engine_token', $env);
        $this->assertStringContainsString('REKRUTMEN_WHATSAPP_ENGINE_AUTO_START=false', $env);
        $this->assertStringNotContainsString('WAG_TOKEN=wgh_engine_token', $env);
        $this->assertStringNotContainsString('/engine/t/', $env);
    }

    public function test_other_apps_get_a_generic_engine_url(): void
    {
        $application = new ClientApplication([
            'name' => 'External CRM',
            'slug' => 'external-crm',
        ]);

        $env = app(IntegrationPack::class)->envSnippet(
            $application,
            'https://hub.test',
            'wgh_hub',
            'wgh_engine',
        );

        $this->assertStringContainsString('WAG_ENGINE_URL=https://hub.test/api/v1/engine', $env);
        $this->assertStringContainsString('WAG_ENGINE_TOKEN=wgh_engine', $env);
        $this->assertStringContainsString('WAG_TOKEN=wgh_hub', $env);
        $this->assertStringNotContainsString('REKRUTMEN_WHATSAPP_ENGINE_URL', $env);
        $this->assertStringNotContainsString('/engine/t/', $env);
    }

    public function test_pack_issues_distinct_application_scoped_credentials_with_separate_abilities(): void
    {
        $application = ClientApplication::query()->create([
            'name' => 'External CRM',
            'slug' => 'external-crm',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);

        $pack = app(IntegrationPack::class)->issue($application, 'https://hub.test');

        $this->assertNotSame($pack['hub']->plainTextToken, $pack['engine']->plainTextToken);
        $this->assertSame($application->getKey(), $pack['hub']->credential->client_application_id);
        $this->assertSame($application->getKey(), $pack['engine']->credential->client_application_id);
        $this->assertSame(['messages:send', 'messages:read'], $pack['hub']->credential->abilities);
        $this->assertSame(['engine:use'], $pack['engine']->credential->abilities);
        $this->assertSame('WAG_ENGINE_TOKEN', $pack['engine']->credential->name);
        $this->assertStringContainsString('WAG_TOKEN='.$pack['hub']->plainTextToken, $pack['env']);
        $this->assertStringContainsString('WAG_ENGINE_TOKEN='.$pack['engine']->plainTextToken, $pack['env']);
    }

    public function test_cesa_retains_number_check_permission_without_engine_access_on_its_hub_token(): void
    {
        $application = new ClientApplication(['slug' => 'web-cesa']);

        $this->assertSame(
            ['messages:send', 'messages:read', 'numbers:check'],
            app(IntegrationPack::class)->hubAbilities($application),
        );
    }
}
