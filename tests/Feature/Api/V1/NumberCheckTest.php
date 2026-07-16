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

    public function test_number_check_queries_waha_fonnte_and_gowa_without_sending_a_waba_message(): void
    {
        $client = $this->createClientApplication(['numbers:check']);
        $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha-check.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        $this->createProviderAccount('fonnte', 'fonnte-primary', [
            'endpoint' => 'https://fonnte-check.test/send',
            'validate_endpoint' => 'https://fonnte-check.test/validate',
            'token' => 'fonnte-secret',
        ]);
        $this->createProviderAccount('gowa', 'gowa-primary', [
            'base_url' => 'https://gowa-check.test',
            'username' => 'gateway',
            'password' => 'gowa-secret',
            'device_id' => 'device-main',
        ]);
        $this->createProviderAccount('waba', 'waba-primary', [
            'base_url' => 'https://graph-meta.test',
            'api_version' => 'v25.0',
            'phone_number_id' => '123456789012345',
            'access_token' => 'meta-token',
        ]);

        Http::fake([
            'waha-check.test/*' => Http::response([
                'numberExists' => true,
                'chatId' => '6281234567890@c.us',
            ]),
            'fonnte-check.test/*' => Http::response([
                'status' => true,
                'registered' => ['6281234567890'],
                'not_registered' => [],
            ]),
            'gowa-check.test/*' => Http::response([
                'code' => 'SUCCESS',
                'message' => 'Success check user',
                'results' => ['is_on_whatsapp' => false],
            ]),
        ]);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '0812-3456-7890'],
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])
            ->assertOk()
            ->assertJsonPath('data.recipient.type', 'phone')
            ->assertJsonPath('data.recipient.value', '6281234567890')
            ->assertJsonPath('data.status', 'conflict')
            ->assertJsonPath('data.registered', null)
            ->assertJsonPath('data.checks.0.provider', 'waha-primary')
            ->assertJsonPath('data.checks.0.status', 'registered')
            ->assertJsonPath('data.checks.1.provider', 'fonnte-primary')
            ->assertJsonPath('data.checks.1.status', 'registered')
            ->assertJsonPath('data.checks.2.provider', 'gowa-primary')
            ->assertJsonPath('data.checks.2.status', 'not_registered')
            ->assertJsonPath('data.checks.3.provider', 'waba-primary')
            ->assertJsonPath('data.checks.3.status', 'unsupported')
            ->assertJsonPath('data.checks.3.reason_code', 'provider_check_unsupported')
            ->assertJsonStructure(['request_id']);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://waha-check.test/api/contacts/check-exists?phone=6281234567890&session=default'
            && $request->hasHeader('X-Api-Key', 'waha-secret'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://fonnte-check.test/validate'
            && $request->hasHeader('Authorization', 'fonnte-secret'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gowa-check.test/user/check?phone=6281234567890'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('gateway:gowa-secret'))
            && $request->hasHeader('X-Device-Id', 'device-main'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'graph-meta.test'));
    }

    public function test_provider_failures_are_unknown_instead_of_false(): void
    {
        $client = $this->createClientApplication(['numbers:check']);
        $this->createProviderAccount('fonnte', 'fonnte-primary', [
            'endpoint' => 'https://fonnte-check.test/send',
            'validate_endpoint' => 'https://fonnte-check.test/validate',
            'token' => 'fonnte-secret',
        ]);
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
        DB::table('provider_accounts')->where('id', $provider['id'])->update(['is_active' => false]);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
        ], [
            'Authorization' => "Bearer {$client['token']}",
        ])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'provider_unavailable');
    }
}
