<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ConnectionFallbackTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_can_add_fallback_provider_to_connection(): void
    {
        config()->set('gateway.provider_endpoints.https_hosts', ['fonnte.test', 'fonnte2.test']);

        $client = $this->createClientApplication(['messages:send', 'messages:read', 'engine:use']);
        $this->createProviderAccount('fonnte', 'fonnte-primary', [
            'endpoint' => 'https://fonnte.test/send',
            'token' => 'a',
        ]);
        $fallback = $this->createProviderAccount('fonnte', 'fonnte-fallback', [
            'endpoint' => 'https://fonnte2.test/send',
            'token' => 'b',
        ]);

        $connectionId = $this->withToken($client['token'])
            ->postJson('/api/v1/connections', [
                'name' => 'Fonnte route',
                'type' => 'provider_route',
                'driver' => 'fonnte',
                'configuration' => [
                    'endpoint' => 'https://fonnte.test/send',
                    'token' => 'a',
                ],
            ])
            ->json('data.id');

        $this->withToken($client['token'])
            ->postJson("/api/v1/connections/{$connectionId}/fallbacks", [
                'provider' => $fallback['slug'],
            ])
            ->assertOk();

        $this->withToken($client['token'])
            ->getJson("/api/v1/connections/{$connectionId}/fallbacks")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.slug', 'fonnte-fallback');
    }
}
