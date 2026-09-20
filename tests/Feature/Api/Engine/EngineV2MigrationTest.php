<?php

namespace Tests\Feature\Api\Engine;

use App\Jobs\EngineRefreshSession;
use App\Jobs\EngineWorkerHeartbeat;
use App\Models\ClientApplication;
use App\Models\EngineIntegration;
use App\Models\EngineSession;
use App\Models\ProviderAccount;
use App\Services\Engine\SessionService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class EngineV2MigrationTest extends TestCase
{
    use BuildsGatewayFixtures, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['gateway.engine.baileys_url' => 'http://runner.test', 'gateway.engine.baileys_token' => 'private-test-token']);
    }

    public function test_legacy_backfill_is_repeatable_and_preserves_runner_identity(): void
    {
        $client = $this->createClientApplication(['engine:use']);
        $provider = $this->createProviderAccount('wag_hub', 'native-old', ['owned_by_application_id' => $client['id'], 'engine_session_id' => 'rekrutmen-1']);
        $uuid = ProviderAccount::findOrFail($provider['id'])->uuid;
        $this->artisan('engine:migrate-legacy')->assertSuccessful();
        $this->artisan('engine:migrate-legacy')->assertSuccessful();
        $this->assertDatabaseCount('engine_sessions', 1);
        $session = EngineSession::firstOrFail();
        $this->assertSame('wgh-'.$uuid, $session->runner_id);
        $this->assertSame('rekrutmen-1', $session->external_reference);
        $this->assertNull($session->provisioning_expires_at);
        Http::fake(fn ($request) => Http::response(['ok' => true, 'canonical_status' => 'STOPPED', 'generation' => $request['generation'], 'revision' => 1]));
        $this->withToken($client['token'])->deleteJson('/api/v1/engine/sessions/rekrutmen-1', ['logout' => false])
            ->assertOk()->assertJsonPath('canonical_status', 'STOPPED');
        $this->assertSame('STOPPED', $session->fresh()->desired_state);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/stop'));
    }

    public function test_abandoned_connect_expires_but_imported_or_explicitly_created_accounts_remain(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $application = ClientApplication::findOrFail($client['id']);
        $service = app(SessionService::class);
        $permanent = $service->create($application, 'permanent', []);
        $provisional = $service->create($application, 'provisional', ['start' => true]);
        $originalOperation = $provisional->pendingOperation;
        $this->travel(11)->minutes();
        Http::fake(fn () => Http::response(['canonical_status' => 'SCAN_QR_CODE', 'generation' => 1, 'revision' => 1]));
        $this->artisan('engine:control-tick')->assertSuccessful();
        $this->assertSame('delete', $provisional->fresh()->pendingOperation->action);
        $this->assertSame('superseded', $originalOperation->fresh()->status);
        $this->assertNull($permanent->fresh()->pending_operation_id);
    }

    public function test_scheduler_cannot_report_workers_ready_until_their_jobs_execute(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        Cache::forget('engine:control-heartbeat');
        Cache::forget('engine:transport-heartbeat');
        Http::fake(fn () => Http::response(['ok' => true, 'events' => [], 'next_cursor' => 0]));
        $this->artisan('engine:control-tick')->assertSuccessful();
        $this->artisan('engine:transport-tick')->assertSuccessful();
        $this->withToken($client['token'])->getJson('/health/ready')->assertStatus(503)
            ->assertJsonPath('checks.control_worker', false)->assertJsonPath('checks.transport_worker', false);
        Queue::assertPushed(EngineWorkerHeartbeat::class, 2);
        (new EngineWorkerHeartbeat('control'))->handle();
        (new EngineWorkerHeartbeat('transport'))->handle();
        $this->getJson('/health/ready')->assertOk()->assertJsonPath('ok', true);
        $this->travel(91)->seconds();
        $this->getJson('/health/ready')->assertStatus(503);
    }

    public function test_background_observations_do_not_block_the_scheduler_or_execute_after_pause(): void
    {
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $sessions = app(SessionService::class);
        $session = $sessions->create(ClientApplication::findOrFail($client['id']), 'refresh', []);
        $session->update(['generation' => 1, 'desired_state' => 'RUNNING']);
        Http::fake(fn () => Http::response(['canonical_status' => 'WORKING', 'generation' => 1, 'revision' => 1]));
        $this->artisan('engine:control-tick')->assertSuccessful();
        Http::assertNothingSent();
        Queue::assertPushed(EngineRefreshSession::class);
        $job = new EngineRefreshSession($session->id);
        $job->handle($sessions);
        $this->assertSame('WORKING', $session->fresh()->status);
        $this->travel(20)->seconds();
        $sessions->command($session, 'stop');
        $job->handle($sessions);
        Http::assertSentCount(1);
        $this->assertSame('STOPPED', $session->fresh()->desired_state);
    }

    public function test_retirement_requires_every_consumer_and_seven_days_of_no_legacy_traffic(): void
    {
        $first = $this->createClientApplication(['engine:use']);
        $second = $this->createClientApplication(config('engine_v2.scopes'));
        $service = app(SessionService::class);
        foreach ([$first, $second] as $client) {
            $service->integration(ClientApplication::findOrFail($client['id']));
        }
        $firstIntegration = EngineIntegration::where('client_application_id', $first['id'])->firstOrFail();
        $firstIntegration->update(['migration_verified_at' => now()->subDays(8)]);
        $this->artisan('engine:legacy-retirement', ['--retire' => true])->assertFailed();
        EngineIntegration::where('client_application_id', $second['id'])->update(['migration_verified_at' => now()->subDays(8)]);
        Http::fake(['*/health' => Http::response(['ok' => true])]);
        $this->withToken($first['token'])->getJson('/api/v1/engine/health');
        $this->artisan('engine:legacy-retirement', ['--retire' => true])->assertFailed();
        $this->travel(8)->days();
        $this->artisan('engine:legacy-retirement', ['--retire' => true])->assertSuccessful();
        $this->getJson('/api/v1/engine/health')->assertStatus(410)->assertJsonPath('error.code', 'legacy_retired');
        $this->withToken($second['token'])->getJson('/api/v2/integration')->assertOk();
    }
}
