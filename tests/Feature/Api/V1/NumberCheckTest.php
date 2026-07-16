<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

final class NumberCheckTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_number_check_requires_its_dedicated_ability(): void
    {
        $client = $this->createClientApplication();

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_number_check_rejects_invalid_numbers_and_provider_selection(): void
    {
        $client = $this->createClientApplication(['numbers:check']);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '123'],
            'provider' => 'waha-primary',
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient.value', 'provider']);
    }

    public function test_number_check_uses_its_own_ordered_route_and_stops_on_a_definitive_result(): void
    {
        $client = $this->createClientApplication(['numbers:check']);
        $waha = $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha-check.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        $fonnte = $this->createProviderAccount('fonnte', 'fonnte-primary', [
            'endpoint' => 'https://fonnte-check.test/send',
            'validate_endpoint' => 'https://fonnte-check.test/validate',
            'token' => 'fonnte-secret',
        ]);
        $gowa = $this->createProviderAccount('gowa', 'gowa-primary', [
            'base_url' => 'https://gowa-check.test',
            'username' => 'gateway',
            'password' => 'gowa-secret',
            'device_id' => 'device-main',
        ]);
        $waba = $this->createProviderAccount('waba', 'waba-primary', [
            'base_url' => 'https://graph-meta.test',
            'api_version' => 'v25.0',
            'phone_number_id' => '123456789012345',
            'access_token' => 'meta-token',
        ]);
        $messageOnly = $this->createProviderAccount('gowa', 'message-only', [
            'base_url' => 'https://message-only.test',
            'username' => 'gateway',
            'password' => 'message-secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$messageOnly['id']]);
        $this->createRoutingPolicy($client['id'], [
            $waba['id'],
            $fonnte['id'],
            $waha['id'],
            $gowa['id'],
        ], routeKey: 'lookup-primary', operation: 'number_check');

        Http::fake([
            'waha-check.test/*' => Http::response([
                'numberExists' => true,
                'chatId' => '6281234567890@c.us',
            ]),
            'fonnte-check.test/*' => Http::response([
                'status' => false,
                'reason' => 'device disconnected',
            ], 503),
            'gowa-check.test/*' => Http::response([
                'code' => 'SUCCESS',
                'message' => 'Success check user',
                'results' => ['is_on_whatsapp' => false],
            ]),
        ]);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '0812-3456-7890'],
            'route_key' => 'lookup-primary',
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])
            ->assertOk()
            ->assertJsonPath('data.recipient.type', 'phone')
            ->assertJsonPath('data.recipient.value', '6281234567890')
            ->assertJsonPath('data.route_key', 'lookup-primary')
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.registered', true)
            ->assertJsonPath('data.checks.0.provider', 'waba-primary')
            ->assertJsonPath('data.checks.0.status', 'unsupported')
            ->assertJsonPath('data.checks.1.provider', 'fonnte-primary')
            ->assertJsonPath('data.checks.1.status', 'unknown')
            ->assertJsonPath('data.checks.2.provider', 'waha-primary')
            ->assertJsonPath('data.checks.2.status', 'registered')
            ->assertJsonCount(3, 'data.checks')
            ->assertJsonStructure(['request_id']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://waha-check.test/api/contacts/check-exists?phone=6281234567890&session=default'
            && $request->hasHeader('X-Api-Key', 'waha-secret'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://fonnte-check.test/validate'
            && $request->hasHeader('Authorization', 'fonnte-secret')
            && $request->hasFile('target', '6281234567890')
            && $request->hasFile('countryCode', '62'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'gowa-check.test')
            || str_contains($request->url(), 'message-only.test'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'graph-meta.test'));
    }

    public function test_provider_failures_are_unknown_instead_of_false(): void
    {
        $client = $this->createClientApplication(['numbers:check']);
        $provider = $this->createProviderAccount('fonnte', 'fonnte-primary', [
            'endpoint' => 'https://fonnte-check.test/send',
            'validate_endpoint' => 'https://fonnte-check.test/validate',
            'token' => 'fonnte-secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']], operation: 'number_check');
        Http::fake([
            'fonnte-check.test/*' => Http::response([
                'status' => false,
                'reason' => 'device disconnected',
            ], 503),
        ]);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'unknown')
            ->assertJsonPath('data.registered', null)
            ->assertJsonPath('data.checks.0.status', 'unknown')
            ->assertJsonPath('data.checks.0.reason_code', 'provider_unavailable');
    }

    public function test_number_check_returns_service_unavailable_without_active_providers(): void
    {
        $client = $this->createClientApplication(['numbers:check']);
        $provider = $this->createProviderAccount('waha', 'waha-inactive', [
            'base_url' => 'https://waha-check.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']], operation: 'number_check');
        DB::table('provider_accounts')->where('id', $provider['id'])->update(['is_active' => false]);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'provider_unavailable');
    }

    public function test_number_check_does_not_query_another_applications_provider(): void
    {
        $owner = $this->createClientApplication(['numbers:check']);
        $other = $this->createClientApplication(['numbers:check']);
        $ownerProvider = $this->createProviderAccount('waha', 'owner-waha', [
            'base_url' => 'https://owner-waha.test',
            'session' => 'default',
            'api_key' => 'owner-secret',
        ]);
        $otherProvider = $this->createProviderAccount('waha', 'other-waha', [
            'base_url' => 'https://other-waha.test',
            'session' => 'default',
            'api_key' => 'other-secret',
        ]);
        $this->createRoutingPolicy($owner['id'], [$ownerProvider['id']], operation: 'number_check');
        $this->createRoutingPolicy($other['id'], [$otherProvider['id']], operation: 'number_check');
        Http::fake([
            'owner-waha.test/*' => Http::response(['numberExists' => true]),
            'other-waha.test/*' => Http::response(['numberExists' => false]),
        ]);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
        ], [
            'Authorization' => "Bearer {$owner['token']}",
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.checks')
            ->assertJsonPath('data.checks.0.provider', 'owner-waha');

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'other-waha.test'));
    }

    public function test_number_check_skips_an_open_circuit_and_never_uses_a_deleted_policy(): void
    {
        $client = $this->createClientApplication(['numbers:check']);
        $openProvider = $this->createProviderAccount('waha', 'open-waha', [
            'base_url' => 'https://open-waha.test',
            'session' => 'default',
            'api_key' => 'open-secret',
        ]);
        $healthyProvider = $this->createProviderAccount('waha', 'healthy-waha', [
            'base_url' => 'https://healthy-waha.test',
            'session' => 'default',
            'api_key' => 'healthy-secret',
        ]);
        DB::table('provider_accounts')
            ->where('id', $openProvider['id'])
            ->update(['circuit_open_until' => now()->addMinutes(5)]);
        $this->createRoutingPolicy(
            $client['id'],
            [$openProvider['id'], $healthyProvider['id']],
            routeKey: 'healthy-route',
            operation: 'number_check',
        );
        $deletedPolicy = $this->createRoutingPolicy(
            $client['id'],
            [$openProvider['id']],
            routeKey: 'deleted-route',
            operation: 'number_check',
        );
        DB::table('routing_policies')
            ->whereIn('id', [$deletedPolicy])
            ->orWhere('key', 'healthy-route')
            ->update(['is_default' => false]);
        DB::table('routing_policies')->where('id', $deletedPolicy)->update(['deleted_at' => now()]);
        Http::fake([
            'healthy-waha.test/*' => Http::response(['numberExists' => true]),
            '*' => Http::response(['numberExists' => false]),
        ]);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'route_key' => 'healthy-route',
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.checks.0.provider', 'open-waha')
            ->assertJsonPath('data.checks.0.status', 'skipped')
            ->assertJsonPath('data.checks.0.reason_code', 'provider_circuit_open')
            ->assertJsonPath('data.checks.1.provider', 'healthy-waha');

        Http::assertSentCount(1);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'route_key' => 'deleted-route',
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'route_unavailable');
    }

    public function test_message_delivery_never_uses_a_number_check_route(): void
    {
        $client = $this->createClientApplication(['messages:send', 'numbers:check']);
        $provider = $this->createProviderAccount('waha', 'check-only-waha', [
            'base_url' => 'https://check-only.test',
            'session' => 'default',
            'api_key' => 'check-secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']], operation: 'number_check');
        Http::fake();

        $this->postMessage($client['token'], 'check-route-isolation', $this->messagePayload('sync'))
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'route_unavailable');

        Http::assertNothingSent();
    }
}
