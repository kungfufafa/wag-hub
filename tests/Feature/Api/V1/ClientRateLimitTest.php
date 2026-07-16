<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ClientRateLimitTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_each_application_has_its_own_configurable_request_limit(): void
    {
        Queue::fake();
        $limited = $this->createClientApplication();
        $other = $this->createClientApplication();

        DB::table('client_applications')
            ->where('id', $limited['id'])
            ->update(['rate_limit_per_minute' => 2]);

        $this->postMessage($limited['token'], 'rate-limit-1', $this->messagePayload())
            ->assertAccepted();
        $this->postMessage($limited['token'], 'rate-limit-2', $this->messagePayload())
            ->assertAccepted();

        $this->postMessage($limited['token'], 'rate-limit-3', $this->messagePayload())
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertHeader('Retry-After');

        $this->postMessage($other['token'], 'other-client-rate-1', $this->messagePayload())
            ->assertAccepted();

        $this->assertDatabaseCount('gateway_messages', 3);
    }

    public function test_number_checks_do_not_consume_the_message_rate_limit_bucket(): void
    {
        Queue::fake();
        $client = $this->createClientApplication(['messages:send', 'numbers:check']);
        DB::table('client_applications')
            ->where('id', $client['id'])
            ->update(['rate_limit_per_minute' => 1]);
        $provider = $this->createProviderAccount('waha', 'separate-limit-waha', [
            'base_url' => 'https://separate-limit.test',
            'session' => 'default',
            'api_key' => 'secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        $this->createRoutingPolicy($client['id'], [$provider['id']], operation: 'number_check');
        Http::fake([
            'separate-limit.test/*' => Http::response(['numberExists' => true]),
        ]);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])->assertOk();

        $this->postMessage($client['token'], 'message-after-check', $this->messagePayload())
            ->assertAccepted();
    }
}
