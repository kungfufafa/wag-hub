<?php

namespace Tests\Feature\Connection;

use App\Models\WhatsAppConnection;
use App\Services\Connection\ConnectionBackfillService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ConnectionBackfillTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_backfill_creates_connection_from_user_linked_session(): void
    {
        $client = $this->createClientApplication();
        $now = now();

        $providerId = \DB::table('provider_accounts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'App session',
            'slug' => 'app-sess-test',
            'driver' => 'wag_hub',
            'configuration' => Crypt::encryptString(json_encode([
                'owned_by_application_id' => $client['id'],
                'engine_session_id' => 'primary',
                'session' => 'wgh-test',
            ], JSON_THROW_ON_ERROR)),
            'is_active' => true,
            'health_status' => 'healthy',
            'consecutive_failures' => 0,
            'timeout_seconds' => 15,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $policyId = $this->createRoutingPolicy($client['id'], [$providerId], 'notification', 'primary');

        $summary = app(ConnectionBackfillService::class)->backfill($client['id']);

        $this->assertSame(1, $summary['created']);
        $connection = WhatsAppConnection::query()->sole();
        $this->assertSame('managed_number', $connection->type);
        $this->assertSame('primary', $connection->session_id);
        $this->assertSame($providerId, $connection->provider_account_id);
        $this->assertSame($policyId, $connection->routing_policy_id);
    }

    public function test_backfill_command_reports_dry_run(): void
    {
        $this->artisan('gateway:backfill-connections', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run');
    }
}
