<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

final class NumberCheckAuditTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_successful_check_persists_requester_encrypted_recipient_and_ordered_attempts(): void
    {
        $client = $this->createClientApplication(['numbers:check']);
        $waba = $this->createProviderAccount('waba', 'audit-waba', [
            'base_url' => 'https://graph-meta.test',
            'api_version' => 'v25.0',
            'phone_number_id' => '123456789012345',
            'access_token' => 'meta-token',
        ]);
        $fonnte = $this->createProviderAccount('fonnte', 'audit-fonnte', [
            'endpoint' => 'https://audit-fonnte.test/send',
            'validate_endpoint' => 'https://audit-fonnte.test/validate',
            'token' => 'fonnte-secret',
        ]);
        $waha = $this->createProviderAccount('waha', 'audit-waha', [
            'base_url' => 'https://audit-waha.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        $policyId = $this->createRoutingPolicy(
            $client['id'],
            [$waba['id'], $fonnte['id'], $waha['id']],
            routeKey: 'audit-route',
            operation: 'number_check',
        );
        Http::fake([
            'audit-fonnte.test/*' => Http::response([
                'status' => false,
                'reason' => 'device disconnected',
            ], 503),
            'audit-waha.test/*' => Http::response(['numberExists' => true]),
        ]);

        $response = $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'route_key' => 'audit-route',
        ], [
            'Authorization' => "Bearer {$client['token']}",
            'X-Correlation-ID' => 'audit-check-001',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonStructure(['data' => ['id']]);

        $auditId = $response->json('data.id');
        $audit = DB::table('number_check_requests')->where('uuid', $auditId)->sole();

        $this->assertSame($client['id'], $audit->client_application_id);
        $this->assertSame($client['credential_id'], $audit->api_credential_id);
        $this->assertSame($policyId, $audit->routing_policy_id);
        $this->assertSame($waha['id'], $audit->resolved_provider_account_id);
        $this->assertSame('audit-check-001', $audit->correlation_id);
        $this->assertSame('audit-route', $audit->route_key);
        $this->assertSame('registered', $audit->status);
        $this->assertSame(1, $audit->registered);
        $this->assertSame('7890', $audit->recipient_last4);
        $this->assertNotSame('6281234567890', $audit->recipient);
        $this->assertStringNotContainsString('6281234567890', $audit->recipient);
        $this->assertNotNull($audit->started_at);
        $this->assertNotNull($audit->finished_at);

        $attempts = DB::table('number_check_attempts')
            ->where('number_check_request_id', $audit->id)
            ->orderBy('sequence')
            ->get();

        $this->assertSame(
            ['unsupported', 'unknown', 'registered'],
            $attempts->pluck('status')->all(),
        );
        $this->assertSame(
            [$waba['id'], $fonnte['id'], $waha['id']],
            $attempts->pluck('provider_account_id')->all(),
        );
        $this->assertSame(
            ['provider_check_unsupported', 'provider_unavailable', null],
            $attempts->pluck('reason_code')->all(),
        );
        $this->assertNotNull($attempts[0]->latency_ms);
        $this->assertNotNull($attempts[1]->latency_ms);
        $this->assertNotNull($attempts[2]->latency_ms);
    }

    public function test_route_failure_is_audited_without_a_provider_attempt(): void
    {
        $client = $this->createClientApplication(['numbers:check']);

        $response = $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'route_key' => 'missing-route',
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'route_unavailable')
            ->assertJsonStructure(['error' => ['audit_id']]);

        $audit = DB::table('number_check_requests')
            ->where('uuid', $response->json('error.audit_id'))
            ->sole();

        $this->assertSame('failed', $audit->status);
        $this->assertSame('route_unavailable', $audit->last_error_code);
        $this->assertNull($audit->routing_policy_id);
        $this->assertNotNull($audit->finished_at);
        $this->assertDatabaseCount('number_check_attempts', 0);
    }

    public function test_rejected_or_invalid_requests_do_not_create_audit_records(): void
    {
        $withoutAbility = $this->createClientApplication(['messages:send']);
        $withAbility = $this->createClientApplication(['numbers:check']);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
        ], [
            'Authorization' => "Bearer {$withoutAbility['token']}",
        ])->assertForbidden();

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '123'],
        ], [
            'Authorization' => "Bearer {$withAbility['token']}",
        ])->assertUnprocessable();

        $this->assertDatabaseCount('number_check_requests', 0);
        $this->assertDatabaseCount('number_check_attempts', 0);
    }
}
