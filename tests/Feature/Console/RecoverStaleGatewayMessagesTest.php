<?php

namespace Tests\Feature\Console;

use App\Jobs\DispatchGatewayMessage;
use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\MessageAttempt;
use App\Models\ProviderAccount;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RecoverStaleGatewayMessagesTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_stale_async_processing_message_without_an_attempt_is_requeued_once(): void
    {
        Queue::fake();
        $message = $this->createProcessingMessage(minutesOld: 10);

        $this->artisan('gateway:recover-stale', ['--minutes' => 5, '--limit' => 100])
            ->expectsOutputToContain('Requeued: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('gateway_messages', [
            'id' => $message->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'recovery_requeued',
            'source' => 'system',
        ]);
        Queue::assertPushed(
            DispatchGatewayMessage::class,
            fn (DispatchGatewayMessage $job): bool => $job->messageId === $message->id,
        );

        $this->artisan('gateway:recover-stale')->assertSuccessful();

        Queue::assertPushed(DispatchGatewayMessage::class, 1);
        $this->assertDatabaseCount('message_events', 1);
    }

    public function test_stale_async_enqueue_failure_is_idempotently_reported_and_later_recovered(): void
    {
        $message = $this->createProcessingMessage(minutesOld: 10);
        $dispatchCalls = 0;
        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')
            ->times(3)
            ->with(Mockery::type(DispatchGatewayMessage::class))
            ->andReturnUsing(function () use (&$dispatchCalls): string {
                $dispatchCalls++;

                if ($dispatchCalls <= 2) {
                    throw new RuntimeException('Queue storage is unavailable.');
                }

                return 'queued-job-id';
            });
        $this->app->instance(BusDispatcher::class, $bus);

        $this->artisan('gateway:recover-stale', ['--minutes' => 5])
            ->expectsOutputToContain('Requeued: 0')
            ->expectsOutputToContain('Enqueue failed: 1')
            ->assertFailed();

        $this->assertSame('queued', $message->fresh()->status);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'enqueue_failed',
            'source' => 'system',
        ]);

        $this->artisan('gateway:recover-stale', ['--minutes' => 5])
            ->expectsOutputToContain('Requeued: 0')
            ->expectsOutputToContain('Enqueue failed: 1')
            ->assertFailed();

        $this->assertSame(1, $message->events()->where('type', 'enqueue_failed')->count());
        $this->assertSame(0, $message->events()->where('type', 'enqueue_recovered')->count());

        $this->artisan('gateway:recover-stale', ['--minutes' => 5])
            ->expectsOutputToContain('Requeued: 1')
            ->expectsOutputToContain('Enqueue failed: 0')
            ->assertSuccessful();

        $this->assertSame(3, $dispatchCalls);
        $this->assertSame(
            ['recovery_requeued', 'enqueue_failed', 'enqueue_recovered'],
            $message->events()->orderBy('id')->pluck('type')->all(),
        );
    }

    public function test_unfinished_started_attempt_becomes_unknown_and_is_never_blindly_resent(): void
    {
        Queue::fake();
        $message = $this->createProcessingMessage(minutesOld: 10);
        $attempt = $this->createAttempt($message, 'started', finished: false);

        $this->artisan('gateway:recover-stale')->assertSuccessful();

        $this->assertDatabaseHas('message_attempts', [
            'id' => $attempt->id,
            'status' => 'outcome_unknown',
            'delivery_certainty' => 'unknown',
            'retry_disposition' => 'reconcile_only',
            'error_code' => 'stale_started_attempt',
        ]);
        $this->assertNotNull($attempt->fresh()->finished_at);
        $this->assertDatabaseHas('gateway_messages', [
            'id' => $message->id,
            'status' => 'outcome_unknown',
        ]);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'recovery_outcome_unknown',
            'source' => 'system',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_completed_accepted_attempt_restores_the_accepted_aggregate_without_resending(): void
    {
        Queue::fake();
        $message = $this->createProcessingMessage(minutesOld: 10);
        $attempt = $this->createAttempt(
            $message,
            'accepted',
            providerMessageId: 'remote-accepted-001',
        );

        $this->artisan('gateway:recover-stale')->assertSuccessful();

        $recovered = $message->fresh();
        $this->assertSame('provider_accepted', $recovered->status);
        $this->assertSame($attempt->provider_account_id, $recovered->accepted_provider_account_id);
        $this->assertSame('remote-accepted-001', $recovered->provider_message_id);
        $this->assertNotNull($recovered->provider_accepted_at);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'recovery_provider_accepted',
            'source' => 'system',
        ]);
        Queue::assertNothingPushed();
    }

    #[DataProvider('completedAttemptProvider')]
    public function test_completed_nonaccepted_attempt_is_mapped_without_resending(
        string $attemptStatus,
        string $expectedMessageStatus,
        string $expectedEvent,
    ): void {
        Queue::fake();
        $message = $this->createProcessingMessage(minutesOld: 10);
        $attempt = $this->createAttempt($message, $attemptStatus);

        $this->artisan('gateway:recover-stale')->assertSuccessful();

        $this->assertSame($attemptStatus, $attempt->fresh()->status);
        $this->assertSame($expectedMessageStatus, $message->fresh()->status);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => $expectedEvent,
            'source' => 'system',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_stale_sync_processing_without_an_attempt_becomes_safely_failed(): void
    {
        Queue::fake();
        $message = $this->createProcessingMessage(minutesOld: 10, mode: 'sync');

        $this->artisan('gateway:recover-stale', ['--minutes' => 5])
            ->expectsOutputToContain('Failed aggregates restored: 1')
            ->assertSuccessful();

        $recovered = $message->fresh();
        $this->assertSame('failed', $recovered->status);
        $this->assertSame('stale_sync_without_attempt', $recovered->last_error_code);
        $this->assertNotNull($recovered->failed_at);
        $this->assertTrue($recovered->isSafeToRetry());
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'recovery_sync_failed_before_attempt',
            'source' => 'system',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_stale_sync_processing_with_a_started_attempt_becomes_outcome_unknown(): void
    {
        Queue::fake();
        $message = $this->createProcessingMessage(minutesOld: 10, mode: 'sync');
        $attempt = $this->createAttempt($message, 'started', finished: false);

        $this->artisan('gateway:recover-stale', ['--minutes' => 5])
            ->expectsOutputToContain('Outcome unknown: 1')
            ->assertSuccessful();

        $this->assertSame('outcome_unknown', $message->fresh()->status);
        $this->assertSame('outcome_unknown', $attempt->fresh()->status);
        $this->assertSame('reconcile_only', $attempt->fresh()->retry_disposition);
        Queue::assertNothingPushed();
    }

    public function test_recovery_ignores_fresh_and_nonprocessing_messages(): void
    {
        Queue::fake();
        $fresh = $this->createProcessingMessage(minutesOld: 4);
        $freshSync = $this->createProcessingMessage(minutesOld: 4, mode: 'sync');
        $queued = $this->createProcessingMessage(minutesOld: 10, status: 'queued');

        $this->artisan('gateway:recover-stale', ['--minutes' => 5])
            ->expectsOutputToContain('Examined: 0')
            ->assertSuccessful();

        $this->assertSame('processing', $fresh->fresh()->status);
        $this->assertSame('processing', $freshSync->fresh()->status);
        $this->assertSame('queued', $queued->fresh()->status);
        $this->assertDatabaseCount('message_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_limit_bounds_the_number_of_messages_recovered_per_run(): void
    {
        Queue::fake();
        $this->createProcessingMessage(minutesOld: 10);
        $this->createProcessingMessage(minutesOld: 10);

        $this->artisan('gateway:recover-stale', ['--limit' => 1])
            ->expectsOutputToContain('Examined: 1')
            ->assertSuccessful();

        $this->assertSame(1, GatewayMessage::query()->where('status', 'queued')->count());
        $this->assertSame(1, GatewayMessage::query()->where('status', 'processing')->count());
        Queue::assertPushed(DispatchGatewayMessage::class, 1);
    }

    public function test_recovery_command_is_registered_with_the_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('gateway:recover-stale')
            ->assertSuccessful();
    }

    public static function completedAttemptProvider(): array
    {
        return [
            'unknown' => ['outcome_unknown', 'outcome_unknown', 'recovery_outcome_unknown'],
            'rejected' => ['rejected', 'failed', 'recovery_failed'],
            'provider failed' => ['provider_failed', 'failed', 'recovery_failed'],
        ];
    }

    private function createProcessingMessage(
        int $minutesOld,
        string $mode = 'async',
        string $status = 'processing',
    ): GatewayMessage {
        $suffix = Str::lower(Str::random(10));
        $application = ClientApplication::forceCreate([
            'name' => "Recovery {$suffix}",
            'slug' => "recovery-{$suffix}",
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);
        $recipient = '6281234567890';
        $body = 'Recovery test notification';

        return GatewayMessage::forceCreate([
            'client_application_id' => $application->id,
            'idempotency_key' => 'recovery:'.Str::uuid(),
            'payload_hash' => hash('sha256', $recipient.'|'.$body),
            'correlation_id' => (string) Str::uuid(),
            'recipient' => $recipient,
            'recipient_hash' => hash_hmac('sha256', $recipient, 'recovery-test'),
            'recipient_last4' => '7890',
            'body' => $body,
            'purpose' => 'notification',
            'route_key' => 'default',
            'mode' => $mode,
            'priority' => 10,
            'status' => $status,
            'queued_at' => now()->subMinutes($minutesOld + 1),
            'processing_at' => now()->subMinutes($minutesOld),
        ]);
    }

    private function createAttempt(
        GatewayMessage $message,
        string $status,
        bool $finished = true,
        ?string $providerMessageId = null,
    ): MessageAttempt {
        $provider = ProviderAccount::forceCreate([
            'name' => 'Recovery provider '.Str::random(6),
            'slug' => 'recovery-provider-'.Str::lower(Str::random(8)),
            'driver' => 'waha',
            'configuration' => [
                'base_url' => 'https://waha.internal.example',
                'session' => 'primary',
                'api_key' => 'test-secret',
            ],
            'is_active' => true,
            'health_status' => 'healthy',
            'timeout_seconds' => 15,
        ]);

        return MessageAttempt::forceCreate([
            'gateway_message_id' => $message->id,
            'provider_account_id' => $provider->id,
            'sequence' => 1,
            'status' => $status,
            'delivery_certainty' => $status === 'accepted' ? 'accepted' : 'not_sent',
            'retry_disposition' => $status === 'outcome_unknown' ? 'reconcile_only' : 'do_not_retry',
            'http_status' => $status === 'accepted' ? 200 : 500,
            'provider_message_id' => $providerMessageId,
            'latency_ms' => 120,
            'error_code' => $status === 'accepted' ? null : "{$status}_error",
            'error_message' => $status === 'accepted' ? null : 'Sanitized provider failure',
            'started_at' => now()->subMinutes(9),
            'finished_at' => $finished ? now()->subMinutes(8) : null,
        ]);
    }
}
