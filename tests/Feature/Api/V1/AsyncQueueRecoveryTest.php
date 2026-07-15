<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchGatewayMessage;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class AsyncQueueRecoveryTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_enqueue_failure_keeps_the_message_queued_and_returns_a_retryable_error(): void
    {
        $client = $this->createClientApplication();
        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(DispatchGatewayMessage::class))
            ->andThrow(new RuntimeException('Queue storage is unavailable.'));
        $this->app->instance(BusDispatcher::class, $bus);

        $response = $this->postMessage(
            $client['token'],
            'enqueue-failure-1',
            $this->messagePayload('async'),
        );

        $response
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'queue_unavailable')
            ->assertJsonPath('error.retryable', true)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.duplicate', false);

        $message = DB::table('gateway_messages')
            ->where('idempotency_key', 'enqueue-failure-1')
            ->sole();

        $this->assertSame('queued', $message->status);
        $this->assertDatabaseCount('gateway_messages', 1);
        $this->assertDatabaseCount('message_attempts', 0);
        $this->assertSame(
            ['queued', 'enqueue_failed'],
            DB::table('message_events')
                ->where('gateway_message_id', $message->id)
                ->orderBy('id')
                ->pluck('type')
                ->all(),
        );
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'enqueue_failed',
            'source' => 'api',
        ]);
    }

    public function test_replay_recovers_a_failed_enqueue_without_creating_a_second_message(): void
    {
        $client = $this->createClientApplication();
        $payload = $this->messagePayload('async');
        $dispatchCalls = 0;
        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')
            ->twice()
            ->with(Mockery::type(DispatchGatewayMessage::class))
            ->andReturnUsing(function () use (&$dispatchCalls): string {
                $dispatchCalls++;

                if ($dispatchCalls === 1) {
                    throw new RuntimeException('Queue storage is unavailable.');
                }

                return 'queued-job-id';
            });
        $this->app->instance(BusDispatcher::class, $bus);

        $first = $this->postMessage(
            $client['token'],
            'enqueue-recovery-1',
            $payload,
        )->assertStatus(503);

        $replay = $this->postMessage(
            $client['token'],
            'enqueue-recovery-1',
            $payload,
        );

        $replay
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.duplicate', true);

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(2, $dispatchCalls);
        $this->assertDatabaseCount('gateway_messages', 1);
        $this->assertDatabaseCount('message_attempts', 0);

        $message = DB::table('gateway_messages')
            ->where('idempotency_key', 'enqueue-recovery-1')
            ->sole();

        $this->assertSame(
            ['queued', 'enqueue_failed', 'enqueue_recovered'],
            DB::table('message_events')
                ->where('gateway_message_id', $message->id)
                ->orderBy('id')
                ->pluck('type')
                ->all(),
        );
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'enqueue_recovered',
            'source' => 'api',
        ]);
    }
}
