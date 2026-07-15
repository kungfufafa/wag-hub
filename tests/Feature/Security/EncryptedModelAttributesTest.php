<?php

namespace Tests\Feature\Security;

use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\MessageAttempt;
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

    public function test_provider_error_details_are_encrypted_at_rest(): void
    {
        $application = ClientApplication::forceCreate([
            'name' => 'Error Encryption App',
            'slug' => 'error-encryption-app',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);
        $provider = ProviderAccount::forceCreate([
            'name' => 'Encrypted Error Provider',
            'slug' => 'encrypted-error-provider',
            'driver' => 'waha',
            'configuration' => [
                'base_url' => 'https://waha.example.test',
                'session' => 'default',
                'api_key' => 'provider-key',
            ],
            'is_active' => true,
            'health_status' => 'degraded',
            'timeout_seconds' => 15,
        ]);
        $message = GatewayMessage::forceCreate([
            'client_application_id' => $application->id,
            'idempotency_key' => 'encrypted-error-message',
            'payload_hash' => str_repeat('a', 64),
            'correlation_id' => (string) Str::uuid(),
            'recipient' => '6281234567890',
            'recipient_hash' => str_repeat('b', 64),
            'recipient_last4' => '7890',
            'body' => 'Message body',
            'purpose' => 'notification',
            'route_key' => 'default',
            'mode' => 'sync',
            'priority' => 10,
            'status' => 'failed',
            'last_error_code' => 'provider_rejected',
            'last_error_message' => 'Sensitive reflected recipient 6281234567890',
            'failed_at' => now(),
        ]);
        $attempt = MessageAttempt::forceCreate([
            'gateway_message_id' => $message->id,
            'provider_account_id' => $provider->id,
            'sequence' => 1,
            'status' => 'rejected',
            'delivery_certainty' => 'not_sent',
            'retry_disposition' => 'do_not_retry',
            'error_code' => 'invalid_message',
            'error_message' => 'Sensitive provider response with OTP 918273',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $rawMessageError = DB::table('gateway_messages')
            ->where('id', $message->id)
            ->value('last_error_message');
        $rawAttemptError = DB::table('message_attempts')
            ->where('id', $attempt->id)
            ->value('error_message');

        $this->assertStringNotContainsString('6281234567890', $rawMessageError);
        $this->assertStringNotContainsString('918273', $rawAttemptError);
        $this->assertSame(
            'Sensitive reflected recipient 6281234567890',
            $message->fresh()->last_error_message,
        );
        $this->assertSame(
            'Sensitive provider response with OTP 918273',
            $attempt->fresh()->error_message,
        );
    }
}
