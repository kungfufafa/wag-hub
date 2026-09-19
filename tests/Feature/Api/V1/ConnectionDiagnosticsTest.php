<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ConnectionDiagnosticsTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_diagnostics_endpoint_returns_operational_summary(): void
    {
        config()->set('gateway.provider_endpoints.https_hosts', ['fonnte.test']);
        $client = $this->createClientApplication(['messages:send', 'messages:read', 'engine:use']);

        $connectionId = $this->withToken($client['token'])
            ->postJson('/api/v1/connections', [
                'name' => 'Fonnte route',
                'type' => 'provider_route',
                'driver' => 'fonnte',
                'configuration' => [
                    'endpoint' => 'https://fonnte.test/send',
                    'token' => 'secret',
                ],
            ])
            ->json('data.id');

        $this->withToken($client['token'])
            ->getJson("/api/v1/connections/{$connectionId}/diagnostics")
            ->assertOk()
            ->assertJsonPath('data.connection_id', $connectionId)
            ->assertJsonStructure([
                'data' => [
                    'messages' => ['total', 'accepted', 'failed', 'outcome_unknown', 'success_rate'],
                    'recent_attempts',
                    'fallback_chain',
                    'problems',
                ],
            ]);
    }
}
