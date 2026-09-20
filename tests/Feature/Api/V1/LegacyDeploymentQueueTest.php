<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchGatewayMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class LegacyDeploymentQueueTest extends TestCase
{
    use BuildsGatewayFixtures, DatabaseMigrations;

    public function test_compose_worker_consumes_accepted_legacy_messages_once(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default']);
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'legacy-migration', [
            'base_url' => 'https://legacy-migration.test', 'api_key' => 'test-secret', 'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake(['https://legacy-migration.test/*' => Http::response(['id' => 'legacy-remote-1'], 201)]);

        $payload = $this->messagePayload('async');
        $accepted = $this->postMessage($client['token'], 'legacy-compose-worker', $payload)
            ->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $job = DB::table('jobs')->sole();
        $stack = Yaml::parseFile(base_path('engine/docker-compose.v2.yml'));
        $workers = collect($stack['services'])->filter(function (array $service) use ($job): bool {
            return in_array('queue:work', $service['command'] ?? [], true)
                && in_array('--queue='.$job->queue, $service['command'] ?? [], true);
        });
        $this->assertNotEmpty($workers->all(), 'Every accepted legacy message needs a deployed worker for its queue.');
        $worker = $workers->first();
        $timeout = (int) substr(collect($worker['command'])->first(fn ($arg) => str_starts_with($arg, '--timeout=')), 10);
        $this->assertGreaterThanOrEqual((new DispatchGatewayMessage(1))->timeout, $timeout);
        $this->assertGreaterThan($timeout, (int) config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($timeout, (int) rtrim($worker['stop_grace_period'], 's'));

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => $job->queue, '--once' => true,
            '--tries' => 1, '--timeout' => $timeout])->assertSuccessful();
        $this->assertDatabaseHas('gateway_messages', ['uuid' => $accepted->json('data.id'), 'status' => 'provider_accepted']);
        $this->assertDatabaseCount('jobs', 0);
        $this->postMessage($client['token'], 'legacy-compose-worker', $payload)
            ->assertOk()->assertJsonPath('data.duplicate', true)->assertJsonPath('data.status', 'provider_accepted');
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(1);
    }
}
