<?php

namespace Tests\Feature\Api\V2;

use App\Exceptions\WhatsAppEngineException;
use App\Jobs\EngineDeliverWebhook;
use App\Jobs\EngineDispatchMessage;
use App\Models\ClientApplication;
use App\Models\EngineEvent;
use App\Models\EngineIntegration;
use App\Models\EngineMedia;
use App\Models\EngineMessage;
use App\Models\EngineSession;
use App\Services\Engine\EngineEventService;
use App\Services\Engine\EngineTransportService;
use App\Services\Engine\RunnerClient;
use App\Services\Engine\SessionService;
use App\Services\Engine\WebhookDestination;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class EngineTransportTest extends TestCase
{
    use BuildsGatewayFixtures, DatabaseMigrations;

    private array $client;

    private EngineSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['gateway.engine.baileys_url' => 'http://runner.test', 'gateway.engine.baileys_token' => 'runner-secret',
            'engine_v2.media_disk' => 'local', 'engine_v2.webhook_allow_private_hosts' => ['127.0.0.1']]);
        $this->client = $this->createClientApplication(['messages:send', 'messages:read', 'media:write', 'media:read']);
        $this->session = EngineSession::query()->create([
            'client_application_id' => $this->client['id'], 'runner_id' => (string) Str::uuid(),
            'name' => 'Support', 'creation_key' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64),
            'desired_state' => 'RUNNING', 'status' => 'WORKING', 'generation' => 1,
            'revision' => 1, 'engine_available' => true, 'observed_at' => now(),
        ]);
    }

    public function test_acceptance_is_durable_and_duplicate_post_is_a_read_even_after_logout(): void
    {
        $this->withToken($this->client['token']);
        $payload = ['to' => '+6281234567890', 'type' => 'text', 'text' => 'Hello'];
        $first = $this->postJson($this->path(), $payload, ['Idempotency-Key' => 'business-1'])
            ->assertStatus(202)->assertJsonPath('data.transport_status', 'queued')->json('data.id');
        $this->session->update(['desired_state' => 'STOPPED', 'status' => 'STOPPED', 'generation' => 2]);
        $this->postJson($this->path(), $payload, ['Idempotency-Key' => 'business-1'])
            ->assertStatus(202)->assertJsonPath('data.id', $first);
        $this->postJson($this->path(), [...$payload, 'text' => 'Changed'], ['Idempotency-Key' => 'business-1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertDatabaseCount('engine_messages', 1);
        Queue::assertPushed(EngineDispatchMessage::class, 1);
        Http::assertNothingSent();
    }

    public function test_unknown_dispatch_is_not_resent_and_missing_runner_journal_cannot_be_called_failed(): void
    {
        $message = $this->accept();
        Http::fake([
            'http://runner.test/sessions/*/messages' => Http::response(['ok' => false, 'status' => 'unknown', 'id' => 'provider-1'], 200),
            'http://runner.test/sessions/*/messages/*' => Http::response(['ok' => false, 'status' => 'failed', 'error_code' => 'message_not_found'], 404),
        ]);
        $service = app(EngineTransportService::class);
        $service->dispatch($message->id);
        $service->dispatch($message->id);
        $service->reconcile($message->fresh());
        $this->assertSame('outcome_unknown', $message->fresh()->transport_status);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'GET'));
    }

    public function test_generation_change_blocks_a_queued_send_before_runner_handoff(): void
    {
        $message = $this->accept();
        $this->session->update(['generation' => 2]);
        app(EngineTransportService::class)->dispatch($message->id);
        $this->assertSame('failed', $message->fresh()->transport_status);
        $this->assertSame('session_changed', $message->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_stale_working_snapshot_does_not_accept_a_new_send(): void
    {
        $this->session->update(['observed_at' => now()->subMinutes(2)]);
        $this->withToken($this->client['token'])->postJson($this->path(), ['to' => '+6281234567890', 'text' => 'stale'],
            ['Idempotency-Key' => 'stale-1'])->assertStatus(409)->assertJsonPath('error.code', 'session_not_working');
        $this->assertDatabaseCount('engine_messages', 0);
        Http::assertNothingSent();
    }

    public function test_expiry_prevents_dispatch_but_repeating_original_post_still_reads_the_message(): void
    {
        $payload = ['to' => '+6281234567890', 'text' => 'short lived', 'expires_at' => now()->addSeconds(10)->toISOString()];
        $id = $this->withToken($this->client['token'])->postJson($this->path(), $payload, ['Idempotency-Key' => 'expiry-1'])
            ->assertStatus(202)->json('data.id');
        $message = EngineMessage::query()->where('uuid', $id)->firstOrFail();
        $this->travel(11)->seconds();
        app(EngineTransportService::class)->dispatch($message->id);
        $this->assertSame('expired', $message->fresh()->transport_status);
        $this->postJson($this->path(), $payload, ['Idempotency-Key' => 'expiry-1'])
            ->assertStatus(202)->assertJsonPath('data.transport_status', 'expired');
        Http::assertNothingSent();
    }

    public function test_early_ack_resolves_unknown_and_later_ack_cannot_regress_receipt(): void
    {
        $message = $this->accept();
        $message->update(['transport_status' => 'outcome_unknown']);
        $service = app(EngineEventService::class);
        $ack = $this->event('message.ack', ['message_id' => 'provider-2', 'idempotency_key' => $message->uuid,
            'ack' => 'read', 'from_me' => true]);
        $event = $service->ingestRunnerEvent($ack);
        $this->assertSame($message->uuid, $event->data['message_id']);
        $this->assertSame('accepted', $message->fresh()->transport_status);
        $this->assertSame('read', $message->fresh()->receipt_status);
        $service->ingestRunnerEvent([...$ack, 'id' => (string) Str::uuid(), 'data' => [...$ack['data'], 'ack' => 'delivered']]);
        $service->ingestRunnerEvent($ack);
        $this->assertSame('read', $message->fresh()->receipt_status);
        $this->assertDatabaseCount('engine_events', 2);
    }

    public function test_message_and_media_identifiers_are_scoped_to_the_credential_owner(): void
    {
        $message = $this->accept();
        $mediaId = $this->withToken($this->client['token'])->post('/api/v2/media', [
            'type' => 'document', 'file' => UploadedFile::fake()->createWithContent('report.txt', 'private document'),
        ], ['Accept' => 'application/json'])->assertStatus(201)->json('data.id');
        $other = $this->createClientApplication(['messages:read', 'messages:send', 'media:read']);
        $this->withToken($other['token'])->getJson('/api/v2/messages/'.$message->uuid)->assertNotFound();
        $this->get('/api/v2/media/'.$mediaId, ['Accept' => 'application/json'])->assertNotFound();
        $this->postJson($this->path(), ['to' => '+6281234567890', 'text' => 'No'], ['Idempotency-Key' => 'other'])
            ->assertNotFound();
        $this->withToken($this->client['token'])->get('/api/v2/media/'.$mediaId)->assertOk()->assertSee('private document');
    }

    public function test_media_type_bytes_and_size_are_validated_before_storage(): void
    {
        $this->withToken($this->client['token'])->post('/api/v2/media', [
            'type' => 'image', 'file' => UploadedFile::fake()->createWithContent('photo.jpg', '<script>not an image</script>'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_media_type');
        config(['engine_v2.media_max_bytes' => 10]);
        $this->post('/api/v2/media', ['type' => 'document', 'file' => UploadedFile::fake()->createWithContent('report.txt', str_repeat('x', 11))],
            ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertDatabaseCount('engine_media', 0);
    }

    public function test_incoming_media_is_private_and_runner_redelivery_is_deduplicated(): void
    {
        $payload = $this->event('message', ['message_id' => 'incoming-1', 'type' => 'document',
            'filename' => 'notes.txt', 'mimetype' => 'text/plain', 'media_base64' => base64_encode('incoming document')]);
        $service = app(EngineEventService::class);
        $first = $service->ingestRunnerEvent($payload);
        $second = $service->ingestRunnerEvent($payload);
        $this->assertSame($first->uuid, $second->uuid);
        $this->assertArrayNotHasKey('media_base64', $first->data);
        $this->assertNotEmpty($first->data['media_id']);
        $this->assertDatabaseCount('engine_media', 1);
        $this->assertDatabaseCount('engine_events', 1);
        $media = EngineMedia::query()->firstOrFail();
        Storage::disk('local')->assertExists($media->path);
        $this->assertStringStartsWith('engine-media/'.$this->client['uuid'].'/', $media->path);
    }

    public function test_transient_incoming_media_storage_failure_keeps_runner_event_unacknowledged(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $payload = $this->event('message', ['message_id' => 'incoming-retry', 'type' => 'document',
            'filename' => 'notes.txt', 'media_base64' => base64_encode('keep these bytes')]);
        Http::fake(['http://runner.test/events*' => Http::response(['events' => [$payload]])]);
        try {
            app(EngineEventService::class)->pollRunner();
            $this->fail('Expected storage failure.');
        } catch (WhatsAppEngineException $exception) {
            $this->assertSame('media_unavailable', $exception->errorCode);
        }
        $this->assertDatabaseCount('engine_events', 0);
        $this->assertDatabaseCount('engine_media', 0);
        Http::assertSentCount(1);
        $this->assertCount(0, Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'));
    }

    public function test_missing_voice_converter_is_actionable_and_keeps_storage_empty(): void
    {
        $pcm = str_repeat("\0", 1600);
        $wave = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', strlen($pcm)).$pcm;
        config(['engine_v2.ffprobe_binary' => '/definitely-missing-ffprobe']);
        $this->withToken($this->client['token'])->post('/api/v2/media', [
            'type' => 'voice', 'file' => UploadedFile::fake()->createWithContent('voice.wav', $wave),
        ], ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('error.code', 'voice_conversion_unavailable');
        $this->assertDatabaseCount('engine_media', 0);
    }

    public function test_voice_upload_converts_a_bounded_wav_to_private_opus_when_ffmpeg_is_installed(): void
    {
        $finder = new ExecutableFinder;
        if (! $finder->find('ffmpeg') || ! $finder->find('ffprobe')) {
            $this->markTestSkipped('FFmpeg integration requires ffmpeg and ffprobe.');
        }
        config(['engine_v2.ffmpeg_binary' => $finder->find('ffmpeg'), 'engine_v2.ffprobe_binary' => $finder->find('ffprobe')]);
        $pcm = str_repeat("\0", 1600);
        $wave = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', strlen($pcm)).$pcm;
        $id = $this->withToken($this->client['token'])->post('/api/v2/media', [
            'type' => 'voice', 'file' => UploadedFile::fake()->createWithContent('voice.wav', $wave),
        ], ['Accept' => 'application/json'])->assertStatus(201)->assertJsonPath('data.mimetype', 'audio/ogg; codecs=opus')->json('data.id');
        $media = EngineMedia::query()->where('uuid', $id)->firstOrFail();
        $this->assertStringStartsWith('OggS', Storage::disk('local')->get($media->path));
    }

    public function test_prior_generation_status_event_cannot_reopen_a_stopped_session(): void
    {
        $this->session->update(['generation' => 2, 'desired_state' => 'STOPPED', 'status' => 'STOPPED']);
        $result = app(EngineEventService::class)->ingestRunnerEvent($this->event('session.status', ['canonical_status' => 'WORKING']));
        $this->assertNull($result);
        $this->assertSame('STOPPED', $this->session->fresh()->status);
        $this->assertDatabaseCount('engine_events', 0);
    }

    public function test_delayed_working_event_keeps_its_original_observation_time_and_is_stale(): void
    {
        $this->session->update(['status' => 'STARTING', 'engine_available' => false, 'observed_at' => now()->subMinutes(10)]);
        $payload = $this->event('session.status', ['canonical_status' => 'WORKING', 'phone_number' => '6281234567890']);
        $payload['occurred_at'] = now()->subMinutes(5)->toISOString();
        $event = app(EngineEventService::class)->ingestRunnerEvent($payload);
        $this->assertSame('WORKING', $this->session->fresh()->status);
        $this->assertTrue($this->session->fresh()->observed_at->lt(now()->subMinutes(4)));
        $this->assertTrue($event->data['stale']);
        $this->assertTrue(app(SessionService::class)->snapshot($this->session->fresh())['stale']);
    }

    public function test_prior_generation_ack_can_confirm_a_message_handed_off_before_stop(): void
    {
        $message = $this->accept();
        $message->update(['transport_status' => 'outcome_unknown', 'provider_message_id' => 'prior-generation']);
        $this->session->update(['generation' => 2, 'desired_state' => 'STOPPED', 'status' => 'STOPPED']);
        app(EngineEventService::class)->ingestRunnerEvent($this->event('message.ack', [
            'message_id' => 'prior-generation', 'from_me' => true, 'ack' => 'delivered',
        ]));
        $this->assertSame('accepted', $message->fresh()->transport_status);
        $this->assertSame('delivered', $message->fresh()->receipt_status);
        $this->assertSame('STOPPED', $this->session->fresh()->status);
    }

    public function test_webhooks_sign_exact_bytes_retry_and_remain_available_for_cursor_replay(): void
    {
        $integration = EngineIntegration::query()->create(['client_application_id' => $this->client['id'],
            'webhook_enabled' => true, 'webhook_url' => 'https://127.0.0.1/events', 'webhook_secret' => 'webhook-secret']);
        $service = app(EngineEventService::class);
        $event = $service->ingestRunnerEvent($this->event('message', ['message_id' => 'incoming-2', 'type' => 'text', 'text' => 'hello']));
        Http::fake(['https://127.0.0.1/events' => Http::sequence()->push([], 503)->push([], 204)]);
        $service->deliver($event->id);
        $this->assertNull($event->fresh()->delivered_at);
        $this->assertSame(1, $event->fresh()->delivery_attempts);
        $this->travel(20)->seconds();
        $service->deliver($event->id);
        $this->assertNotNull($event->fresh()->delivered_at);
        Http::assertSent(function (Request $request): bool {
            $timestamp = $request->header('X-Wag-Timestamp')[0];

            return hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->body(), 'webhook-secret'), $request->header('X-Wag-Signature')[0]);
        });
        $this->assertNotNull($integration->fresh()->webhook_last_success_at);
        $this->withToken($this->client['token'])->getJson('/api/v2/events')->assertOk()
            ->assertJsonPath('data.events.0.id', $event->uuid)->assertJsonPath('data.next_cursor', (string) $event->id);
        $this->getJson('/api/v2/events?after='.$event->id)->assertOk()->assertJsonCount(0, 'data.events');
    }

    public function test_expired_webhook_retry_window_retains_event_for_seven_day_replay(): void
    {
        EngineIntegration::query()->create(['client_application_id' => $this->client['id'],
            'webhook_enabled' => true, 'webhook_url' => 'https://127.0.0.1/events', 'webhook_secret' => 'secret']);
        $service = app(EngineEventService::class);
        $event = $service->ingestRunnerEvent($this->event('message', ['type' => 'text', 'text' => 'retained']));
        $this->travel(25)->hours();
        $service->deliver($event->id);
        $this->assertSame('retry_window_exhausted', $event->fresh()->delivery_error);
        $this->assertCount(1, $service->replay(ClientApplication::findOrFail($this->client['id']))['events']);
        Http::assertNothingSent();
    }

    public function test_inactive_installation_cannot_starve_the_webhook_batch(): void
    {
        $this->assertIneligibleWebhookDoesNotStarve('inactive');
    }

    public function test_deleted_installation_cannot_starve_the_webhook_batch(): void
    {
        $this->assertIneligibleWebhookDoesNotStarve('deleted');
    }

    public function test_missing_webhook_secret_cannot_starve_the_webhook_batch(): void
    {
        $this->assertIneligibleWebhookDoesNotStarve('missing_secret');
    }

    public function test_reactivated_installation_can_deliver_its_pending_webhook_within_the_retry_window(): void
    {
        EngineIntegration::query()->create(['client_application_id' => $this->client['id'],
            'webhook_enabled' => true, 'webhook_url' => 'https://127.0.0.1/events', 'webhook_secret' => 'secret']);
        $application = ClientApplication::findOrFail($this->client['id']);
        $service = app(EngineEventService::class);
        $event = $service->publishSession($this->session);
        $application->update(['is_active' => false]);
        $service->deliver($event->id);
        $this->assertNull($event->fresh()->failed_at);
        $this->assertSame(0, $event->fresh()->delivery_attempts);
        Http::assertNothingSent();

        $this->travel(1)->hours();
        $application->update(['is_active' => true]);
        Http::fake(['https://127.0.0.1/events' => Http::response([], 204)]);
        $service->deliver($event->id);
        $this->assertNotNull($event->fresh()->delivered_at);
        $this->assertSame(1, $event->fresh()->delivery_attempts);
        Http::assertSentCount(1);
    }

    public function test_disabled_webhooks_exhaust_the_retry_window_and_remain_replayable(): void
    {
        $this->freezeTime();
        EngineIntegration::query()->create(['client_application_id' => $this->client['id'],
            'webhook_enabled' => false, 'webhook_url' => 'https://127.0.0.1/events', 'webhook_secret' => 'secret']);
        $application = ClientApplication::findOrFail($this->client['id']);
        $application->update(['is_active' => false]);
        $service = app(EngineEventService::class);
        $directEvent = $service->publishSession($this->session);
        $scheduledEvent = $service->publishSession($this->session);
        $this->travel(24)->hours();
        $service->deliver($directEvent->id);
        $this->assertSame('retry_window_exhausted', $directEvent->fresh()->delivery_error);

        Http::fake(['http://runner.test/events*' => Http::response(['events' => []])]);
        $this->artisan('engine:transport-tick')->assertSuccessful();
        $this->assertSame('retry_window_exhausted', $scheduledEvent->fresh()->delivery_error);
        $this->assertNotNull($scheduledEvent->fresh()->failed_at);
        $this->assertNull($scheduledEvent->fresh()->next_attempt_at);
        $this->assertCount(2, $service->replay($application)['events']);
        Queue::assertNotPushed(EngineDeliverWebhook::class);
        Http::assertSentCount(1);
    }

    public function test_runner_event_is_acknowledged_only_after_hub_commit(): void
    {
        $payload = $this->event('message', ['type' => 'text', 'text' => 'durable']);
        Http::fake(function (Request $request) use ($payload) {
            if ($request->method() === 'GET') {
                return Http::response(['events' => [$payload], 'next_cursor' => '00000000000000000001']);
            }
            $this->assertDatabaseHas('engine_events', ['source_event_id' => $payload['id']]);

            return Http::response(['ok' => true]);
        });
        $this->assertSame(1, app(EngineEventService::class)->pollRunner());
        Http::assertSentCount(2);
    }

    public function test_refresh_observation_rolls_back_if_its_outbox_write_fails(): void
    {
        Http::fake(['http://runner.test/*' => Http::response(['ok' => true, 'canonical_status' => 'FAILED', 'generation' => 1, 'revision' => 2])]);
        $outbox = \Mockery::mock(EngineEventService::class);
        $outbox->shouldReceive('publishSession')->once()->andThrow(new \RuntimeException('outbox unavailable'));
        $this->app->instance(EngineEventService::class, $outbox);
        try {
            app(SessionService::class)->refresh($this->session);
            $this->fail('Expected outbox failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        }
        $this->assertSame('WORKING', $this->session->fresh()->status);
        $this->assertSame(1, $this->session->fresh()->revision);
    }

    public function test_failed_old_poll_does_not_override_a_newer_generation(): void
    {
        $runner = \Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('request')->once()->andReturnUsing(function (): never {
            $this->session->update(['generation' => 2, 'revision' => 2, 'engine_available' => true]);
            throw new WhatsAppEngineException('Runner unavailable.', 503, 'engine_unreachable');
        });
        $snapshot = $this->session->fresh();
        (new SessionService($runner))->refresh($snapshot);
        $this->assertSame(2, $this->session->fresh()->generation);
        $this->assertTrue($this->session->fresh()->engine_available);
        $this->assertNull($this->session->fresh()->last_error);
        $this->assertDatabaseCount('engine_events', 0);
    }

    public function test_equivalent_repeated_start_does_not_supersede_a_pending_operation(): void
    {
        $service = app(SessionService::class);
        $first = $service->command($this->session, 'start', ['mode' => 'qr', 'phone' => null]);
        $second = $service->command($this->session, 'start', ['mode' => 'qr']);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('engine_operations', 1);
        $this->assertDatabaseCount('engine_events', 1);
        $this->assertSame('session.status', EngineEvent::query()->firstOrFail()->event);
    }

    public function test_webhook_destination_blocks_non_global_networks_and_disables_proxy_resolution(): void
    {
        config(['engine_v2.webhook_allow_private_hosts' => []]);
        $guard = app(WebhookDestination::class);
        foreach (['127.0.0.1', '100.64.0.1', '198.18.0.1', '192.0.0.1', '224.0.0.1', '[::ffff:127.0.0.1]', '[2001:db8::1]'] as $host) {
            try {
                $guard->options('https://'.$host.'/events');
                $this->fail('Expected non-global destination to be blocked: '.$host);
            } catch (WhatsAppEngineException $exception) {
                $this->assertSame('webhook_destination_blocked', $exception->errorCode);
            }
        }
        $this->assertSame('', $guard->options('https://8.8.8.8/events')['proxy']);
        $this->assertSame('', $guard->options('https://[2001:4860:4860::8888]/events')['proxy']);
        Http::assertNothingSent();
    }

    private function assertIneligibleWebhookDoesNotStarve(string $reason): void
    {
        $integration = EngineIntegration::query()->create(['client_application_id' => $this->client['id'],
            'webhook_enabled' => true, 'webhook_url' => 'https://127.0.0.1/events', 'webhook_secret' => 'secret']);
        $service = app(EngineEventService::class);
        for ($index = 0; $index < 50; $index++) {
            $service->publishSession($this->session);
        }
        $application = ClientApplication::findOrFail($this->client['id']);
        if ($reason === 'deleted') {
            $application->delete();
        } elseif ($reason === 'inactive') {
            $application->update(['is_active' => false]);
        } else {
            $integration->update(['webhook_secret' => null]);
        }
        $blockedEvent = EngineEvent::query()->oldest('id')->firstOrFail();
        $service->deliver($blockedEvent->id);
        Http::assertNothingSent();

        $activeClient = $this->createClientApplication();
        EngineIntegration::query()->create(['client_application_id' => $activeClient['id'],
            'webhook_enabled' => true, 'webhook_url' => 'https://127.0.0.1/events', 'webhook_secret' => 'other-secret']);
        $activeSession = $this->session->replicate(['uuid', 'runner_id', 'creation_key']);
        $activeSession->fill(['client_application_id' => $activeClient['id'],
            'runner_id' => (string) Str::uuid(), 'creation_key' => (string) Str::uuid()])->save();
        $activeEvent = $service->publishSession($activeSession);
        Http::fake(['http://runner.test/events*' => Http::response(['events' => []])]);

        $this->artisan('engine:transport-tick', ['--limit' => 50])->assertSuccessful();

        Queue::assertPushed(EngineDeliverWebhook::class, 1);
        Queue::assertPushed(EngineDeliverWebhook::class, fn (EngineDeliverWebhook $job): bool => $job->eventId === $activeEvent->id);
        $this->assertNull($blockedEvent->fresh()->failed_at);
        $this->assertSame(0, $blockedEvent->fresh()->delivery_attempts);
        Http::assertSentCount(1);
    }

    private function accept(): EngineMessage
    {
        return app(EngineTransportService::class)->accept(ClientApplication::findOrFail($this->client['id']),
            $this->session->uuid, ['to' => '+6281234567890', 'type' => 'text', 'text' => 'Hello'], (string) Str::uuid());
    }

    private function path(): string
    {
        return '/api/v2/sessions/'.$this->session->uuid.'/messages';
    }

    private function event(string $name, array $data): array
    {
        return ['id' => (string) Str::uuid(), 'cursor' => '00000000000000000001', 'event' => $name,
            'session_id' => $this->session->runner_id, 'generation' => 1, 'revision' => 2,
            'occurred_at' => now()->toISOString(), 'data' => $data];
    }
}
