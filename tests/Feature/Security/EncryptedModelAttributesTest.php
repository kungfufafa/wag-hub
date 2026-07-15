<?php

namespace Tests\Feature\Security;

use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EncryptedModelAttributesTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_configuration_is_encrypted_at_rest(): void
    {
        $configuration = [
            'base_url' => 'https://waha.internal.example',
            'session' => 'primary',
            'api_key' => 'provider-secret-value',
        ];

        $provider = ProviderAccount::forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => 'WAHA Primary',
            'slug' => 'waha-primary',
            'driver' => 'waha',
            'configuration' => $configuration,
            'is_active' => true,
            'health_status' => 'healthy',
            'consecutive_failures' => 0,
            'timeout_seconds' => 15,
        ]);

        $rawConfiguration = DB::table('provider_accounts')
            ->where('id', $provider->id)
            ->value('configuration');

        $this->assertIsString($rawConfiguration);
        $this->assertNotSame(json_encode($configuration), $rawConfiguration);
        $this->assertStringNotContainsString('provider-secret-value', $rawConfiguration);
        $this->assertStringNotContainsString('waha.internal.example', $rawConfiguration);
        $this->assertSame($configuration, $provider->fresh()->configuration);
    }

    public function test_message_recipient_and_body_are_encrypted_at_rest(): void
    {
        $application = ClientApplication::forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => 'Web SAM',
            'slug' => 'web-sam',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);
        $recipient = '6281234567890';
        $body = 'Kode OTP rahasia Anda adalah 918273';

        $message = GatewayMessage::forceCreate([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $application->id,
            'idempotency_key' => 'otp:login:example-001',
            'payload_hash' => hash('sha256', $recipient.'|'.$body),
            'correlation_id' => (string) Str::uuid(),
            'recipient' => $recipient,
            'recipient_hash' => hash_hmac('sha256', $recipient, 'test-key'),
            'recipient_last4' => '7890',
            'body' => $body,
            'purpose' => 'otp',
            'route_key' => 'default',
            'mode' => 'sync',
            'priority' => 100,
            'status' => 'processing',
            'expires_at' => now()->addMinutes(5),
            'processing_at' => now(),
        ]);

        $raw = DB::table('gateway_messages')->where('id', $message->id)->first();

        $this->assertNotNull($raw);
        $this->assertNotSame($recipient, $raw->recipient);
        $this->assertNotSame($body, $raw->body);
        $this->assertStringNotContainsString($recipient, $raw->recipient);
        $this->assertStringNotContainsString('918273', $raw->body);

        $reloaded = $message->fresh();

        $this->assertSame($recipient, $reloaded->recipient);
        $this->assertSame($body, $reloaded->body);
    }
}
