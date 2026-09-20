<?php

namespace Tests\Feature\Api\Engine;

use App\Jobs\ProcessEngineOperation;
use App\Models\ClientApplication;
use App\Models\EngineIntegration;
use App\Models\EngineOperation;
use App\Models\EngineSession;
use App\Services\Engine\SessionService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class EngineV2ControlTest extends TestCase
{
    use BuildsGatewayFixtures, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['gateway.engine.baileys_url' => 'http://runner.test', 'gateway.engine.baileys_token' => 'private-test-token']);
    }

    public function test_qr_create_needs_no_phone_and_repeated_click_reuses_session(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $this->withToken($client['token']);
        $first = $this->postJson('/api/v2/sessions', ['start' => true], ['Idempotency-Key' => 'connect-1'])->assertCreated();
        $first->assertJsonPath('data.session.desired_state', 'RUNNING')->assertJsonPath('data.session.phone_number', null)
            ->assertJsonPath('data.operation.status', 'pending');
        $this->postJson('/api/v2/sessions', ['start' => true], ['Idempotency-Key' => 'connect-1'])
            ->assertCreated()->assertJsonPath('data.session.id', $first->json('data.session.id'));
        $this->postJson('/api/v2/sessions', ['start' => true, 'name' => 'Different'], ['Idempotency-Key' => 'connect-1'])
            ->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertDatabaseCount('engine_sessions', 1);
        $this->assertDatabaseCount('engine_operations', 1);
        Queue::assertPushed(ProcessEngineOperation::class, 1);
    }

    public function test_ownership_scopes_and_revocation_apply_to_all_control_resources(): void
    {
        $first = $this->createClientApplication(config('engine_v2.scopes'));
        $second = $this->createClientApplication(config('engine_v2.scopes'));
        $created = $this->withToken($first['token'])->postJson('/api/v2/sessions', ['start' => true], ['Idempotency-Key' => 'one'])->assertCreated();
        $id = $created->json('data.session.id');
        $operation = $created->json('data.operation.id');
        $this->withToken($second['token'])->getJson('/api/v2/sessions/'.$id)->assertNotFound();
        $this->getJson('/api/v2/operations/'.$operation)->assertNotFound();
        $this->postJson('/api/v2/sessions/'.$id.'/stop')->assertNotFound();
        $reader = $this->createClientApplication(['sessions:read']);
        $this->withToken($reader['token'])->postJson('/api/v2/sessions', [], ['Idempotency-Key' => 'two'])->assertForbidden();
        $revoked = $this->createClientApplication(config('engine_v2.scopes'), revoked: true);
        $this->withToken($revoked['token'])->getJson('/api/v2/integration')->assertUnauthorized();
    }

    public function test_capacity_counts_stopped_accounts_and_cannot_exceed_grant_or_drop_below_usage(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $this->withToken($client['token'])->getJson('/api/v2/integration')->assertOk();
        EngineIntegration::query()->where('client_application_id', $client['id'])->update(['configured_limit' => 1, 'maximum_allowed' => 2]);
        $this->postJson('/api/v2/sessions', [], ['Idempotency-Key' => 'one'])->assertCreated();
        $this->postJson('/api/v2/sessions', [], ['Idempotency-Key' => 'two'])->assertConflict()->assertJsonPath('error.code', 'capacity_exceeded');
        $this->patchJson('/api/v2/integration', ['configured_limit' => 0])->assertConflict();
        $this->patchJson('/api/v2/integration', ['configured_limit' => 3])->assertConflict();
        $this->patchJson('/api/v2/integration', ['configured_limit' => 2])->assertOk()->assertJsonPath('data.capacity.used', 1);
        $this->postJson('/api/v2/sessions', [], ['Idempotency-Key' => 'two'])->assertCreated();
    }

    public function test_logout_outage_preserves_observation_and_leaves_durable_pending_intent(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $session = $this->working($client['id']);
        $observed = $session->observed_at->toISOString();
        Http::fake(fn () => Http::failedConnection());
        $response = $this->withToken($client['token'])->postJson('/api/v2/sessions/'.$session->uuid.'/logout')->assertAccepted();
        app(SessionService::class)->execute(EngineOperation::query()->where('uuid', $response->json('data.operation.id'))->firstOrFail());
        $session->refresh();
        $this->assertSame('STOPPED', $session->desired_state);
        $this->assertSame('WORKING', $session->status);
        $this->assertSame($observed, $session->observed_at->toISOString());
        $this->assertFalse($session->engine_available);
        $this->assertSame('pending', $session->pendingOperation->status);
        $this->getJson('/api/v2/sessions/'.$session->uuid)->assertOk()->assertJsonPath('data.stale', true)->assertJsonPath('data.status', 'WORKING');
    }

    public function test_stop_logout_delete_are_distinct_and_delete_releases_capacity_after_confirmation(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $session = $this->working($client['id']);
        Http::fake(fn ($request) => Http::response(['ok' => true, 'canonical_status' => 'STOPPED', 'generation' => $request['generation'], 'revision' => 1, 'logout_confirmed' => true]));
        $this->withToken($client['token']);
        foreach (['stop', 'logout', 'delete'] as $action) {
            $response = $action === 'delete'
                ? $this->deleteJson('/api/v2/sessions/'.$session->uuid)
                : $this->postJson('/api/v2/sessions/'.$session->uuid.'/'.$action);
            $response->assertAccepted();
            $operation = EngineOperation::query()->where('uuid', $response->json('data.operation.id'))->firstOrFail();
            app(SessionService::class)->execute($operation);
            $this->getJson('/api/v2/operations/'.$operation->uuid)->assertOk()->assertJsonPath('data.status', 'completed');
            $this->assertSame($action === 'delete' ? 0 : 1, EngineSession::query()->count());
        }
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/stop'));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/logout'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_old_generation_and_older_revision_cannot_resurrect_a_stopped_session(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $session = $this->working($client['id']);
        $session->update(['status' => 'STOPPED', 'desired_state' => 'STOPPED', 'generation' => 3, 'runner_revision' => 10]);
        $service = app(SessionService::class);
        $service->observe($session, ['canonical_status' => 'WORKING', 'generation' => 2, 'revision' => 99]);
        $service->observe($session, ['canonical_status' => 'WORKING', 'generation' => 3, 'revision' => 9]);
        $this->assertSame('STOPPED', $session->fresh()->status);
    }

    public function test_delete_does_not_release_capacity_without_confirmed_teardown(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $session = $this->working($client['id']);
        Http::fake(fn ($request) => Http::response(['ok' => true, 'canonical_status' => 'WORKING', 'generation' => $request['generation'], 'revision' => 1]));
        $operation = app(SessionService::class)->command($session, 'delete');
        app(SessionService::class)->execute($operation);
        $this->assertSame('pending', $operation->fresh()->status);
        $this->assertSame('operation_incomplete', $operation->fresh()->last_error);
        $this->assertDatabaseCount('engine_sessions', 1);
        $this->assertFalse($session->fresh()->trashed());
    }

    public function test_qr_challenges_are_not_stored_or_published_and_are_never_cacheable(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $session = $this->working($client['id']);
        Http::fake(['*/auth/qr' => Http::response(['challenge_id' => 'challenge-1', 'qr' => 'secret-qr', 'expires_at' => now()->addMinute()->toISOString()])]);
        $this->withToken($client['token'])->getJson('/api/v2/sessions/'.$session->uuid.'/auth/qr')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.qr', 'secret-qr');
        $this->assertNull($session->fresh()->metadata);
        $this->assertDatabaseCount('engine_events', 1);
    }

    public function test_runner_boot_authorization_exposes_only_ids_generation_and_desired_state(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $session = $this->working($client['id']);
        $this->withToken($client['token'])->getJson('/internal/engine/desired-sessions')->assertUnauthorized();
        $this->withToken('private-test-token')->getJson('/internal/engine/desired-sessions')->assertOk()
            ->assertExactJson(['sessions' => [['id' => $session->runner_id, 'generation' => 1, 'desired_state' => 'RUNNING']]]);
    }

    private function working(int $applicationId): EngineSession
    {
        $session = app(SessionService::class)->create(ClientApplication::findOrFail($applicationId), 'test-session', []);
        $session->update(['status' => 'WORKING', 'desired_state' => 'RUNNING', 'generation' => 1, 'observed_at' => now(), 'engine_available' => true, 'authenticated_at' => now()]);

        return $session;
    }
}
