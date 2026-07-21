<?php

namespace Tests\Feature\Models;

use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GatewayMessageRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_non_expired_message_is_safe_to_retry(): void
    {
        $message = $this->createMessage('failed', now()->addMinute());

        $this->assertTrue($message->isSafeToRetry());
    }

    public function test_outcome_unknown_non_expired_message_is_safe_to_retry(): void
    {
        $message = $this->createMessage('outcome_unknown', now()->addMinute());

        $this->assertTrue($message->isSafeToRetry());
    }

    public function test_failed_message_past_its_expiry_is_not_safe_to_retry(): void
    {
        $message = $this->createMessage('failed', now()->subSecond());

        $this->assertFalse($message->isSafeToRetry());
    }

    #[DataProvider('unsafeStatusProvider')]
    public function test_non_retryable_status_is_not_safe_to_retry(string $status): void
    {
        $message = $this->createMessage($status, now()->addMinute());

        $this->assertFalse($message->isSafeToRetry());
    }

    public function test_queue_for_retry_atomically_transitions_a_safe_message_only_once(): void
    {
        $message = $this->createMessage('failed', now()->addMinute());
        $staleCopy = GatewayMessage::query()->findOrFail($message->id);

        $message->queueForRetry();

        $reloaded = $message->fresh();
        $this->assertSame('queued', $reloaded->status);
        $this->assertNotNull($reloaded->queued_at);
        $this->assertNull($reloaded->processing_at);
        $this->assertNull($reloaded->failed_at);
        $this->assertNull($reloaded->last_error_code);
        $this->assertNull($reloaded->last_error_message);

        $this->expectException(DomainException::class);
        $staleCopy->queueForRetry();
    }

    public function test_queue_for_retry_clears_outcome_unknown_markers(): void
    {
        $message = $this->createMessage('outcome_unknown', now()->addMinute());
        $message->forceFill([
            'outcome_unknown_at' => now()->subMinute(),
            'last_error_code' => 'indeterminate_provider_response',
            'last_error_message' => 'WAHA mengembalikan respons yang tidak dapat dipastikan.',
        ])->save();

        $message->queueForRetry();

        $reloaded = $message->fresh();
        $this->assertSame('queued', $reloaded->status);
        $this->assertNull($reloaded->outcome_unknown_at);
        $this->assertNull($reloaded->last_error_code);
        $this->assertNull($reloaded->last_error_message);
    }

    #[DataProvider('guardedMessageProvider')]
    public function test_queue_for_retry_rejects_unsafe_state_without_changing_it(
        string $status,
        bool $isExpired,
    ): void {
        $message = $this->createMessage(
            $status,
            $isExpired ? now()->subSecond() : now()->addMinute(),
        );

        try {
            $message->queueForRetry();
            $this->fail('Unsafe retry should throw a domain exception.');
        } catch (DomainException) {
            $this->assertSame($status, $message->fresh()->status);
        }
    }

    public static function unsafeStatusProvider(): array
    {
        return [
            'queued' => ['queued'],
            'processing' => ['processing'],
            'accepted' => ['provider_accepted'],
            'expired' => ['expired'],
            'dead letter' => ['dead_letter'],
        ];
    }

    public static function guardedMessageProvider(): array
    {
        return [
            'already queued' => ['queued', false],
            'provider accepted' => ['provider_accepted', false],
            'failed but expired' => ['failed', true],
            'outcome unknown but expired' => ['outcome_unknown', true],
        ];
    }

    private function createMessage(string $status, mixed $expiresAt): GatewayMessage
    {
        $application = ClientApplication::forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => 'Retry Test '.Str::random(8),
            'slug' => 'retry-test-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);
        $recipient = '6281234567890';
        $body = 'Retry-safe notification';

        return GatewayMessage::forceCreate([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $application->id,
            'idempotency_key' => 'retry:'.Str::uuid(),
            'payload_hash' => hash('sha256', $recipient.'|'.$body),
            'correlation_id' => (string) Str::uuid(),
            'recipient' => $recipient,
            'recipient_hash' => hash_hmac('sha256', $recipient, 'test-key'),
            'recipient_last4' => '7890',
            'body' => $body,
            'purpose' => 'notification',
            'route_key' => 'default',
            'mode' => 'async',
            'priority' => 10,
            'status' => $status,
            'expires_at' => $expiresAt,
            'processing_at' => $status === 'failed' ? now()->subMinute() : null,
            'failed_at' => $status === 'failed' ? now() : null,
            'last_error_code' => $status === 'failed' ? 'providers_failed' : null,
            'last_error_message' => $status === 'failed' ? 'Previous provider failure' : null,
        ]);
    }
}
