<?php

namespace Tests\Feature\Delivery;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ProviderEndpointEnforcementTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    /**
     * @param  array<string, string>  $configuration
     */
    #[DataProvider('blockedProviderEndpoints')]
    public function test_drivers_never_send_http_to_a_provider_host_outside_the_allowlist(
        string $driver,
        array $configuration,
    ): void {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount(
            $driver,
            "{$driver}-blocked-endpoint",
            $configuration,
        );
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake();

        $this->postMessage(
            $client['token'],
            "{$driver}-blocked-endpoint",
            $this->messagePayload('sync'),
        )->assertStatus(503);

        Http::assertNothingSent();
        $this->assertDatabaseHas('message_attempts', [
            'provider_account_id' => $provider['id'],
            'status' => 'provider_failed',
            'error_code' => 'provider_endpoint_not_allowed',
        ]);
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function blockedProviderEndpoints(): array
    {
        return [
            'WAHA' => [
                'waha',
                [
                    'base_url' => 'https://attacker.example',
                    'api_key' => 'waha-secret',
                    'session' => 'default',
                ],
            ],
            'Fonnte' => [
                'fonnte',
                [
                    'endpoint' => 'https://attacker.example/send',
                    'token' => 'fonnte-secret',
                ],
            ],
        ];
    }
}
