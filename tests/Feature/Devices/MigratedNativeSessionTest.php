<?php

namespace Tests\Feature\Devices;

use App\Exceptions\WhatsAppEngineException;
use App\Filament\Pages\WhatsAppDevices;
use App\Filament\Resources\ProviderAccounts\Pages\ListProviderAccounts;
use App\Models\EngineSession;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\WhatsAppSessionManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class MigratedNativeSessionTest extends TestCase
{
    use BuildsGatewayFixtures, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['gateway.engine.baileys_url' => 'http://runner.test', 'gateway.engine.baileys_token' => 'private-test-token']);
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_active' => true]));
    }

    public function test_mapped_admin_disconnect_connect_and_restart_use_authoritative_generations(): void
    {
        [$provider, $session] = $this->mapped();
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = [$request->method(), $request->url(), $request['generation']];

            return Http::response(['ok' => true, 'canonical_status' => str_ends_with($request->url(), '/start') ? 'WORKING' : 'STOPPED',
                'generation' => $request['generation'], 'revision' => 1, 'phone_number' => '+6281234567890']);
        });
        $manager = app(WhatsAppSessionManager::class);

        $this->assertSame('stopped', $manager->disconnect($provider)->value);
        $this->assertSame('STOPPED', $session->fresh()->desired_state);
        $this->assertSame('working', $manager->connect($provider)->value);
        $this->assertSame('working', $manager->restart($provider)->value);

        $base = 'http://runner.test/sessions/'.$session->runner_id;
        $this->assertSame([
            ['POST', $base.'/logout', 1], ['POST', $base.'/start', 2],
            ['POST', $base.'/stop', 3], ['POST', $base.'/start', 4],
        ], $calls);
        $this->assertSame(4, $session->fresh()->generation);
        $this->assertSame('RUNNING', $session->fresh()->desired_state);
        $this->assertSame('working', $provider->fresh()->session_status);
        $this->assertDatabaseCount('engine_operations', 4);
    }

    public function test_qr_and_pairing_challenges_use_fenced_endpoint_and_are_not_saved_in_legacy_metadata(): void
    {
        [$provider, $session] = $this->mapped();
        $manager = app(WhatsAppSessionManager::class);
        $obsolete = false;
        Http::fake(function (Request $request) use ($session, &$obsolete) {
            if ($obsolete) {
                return Http::response(['generation' => 0, 'qr' => 'obsolete', 'pairing_code' => 'obsolete']);
            }
            $generation = $request->method() === 'GET' ? $session->fresh()->generation : $request['generation'];

            return Http::response(['ok' => true, 'canonical_status' => 'SCAN_QR_CODE', 'generation' => $generation,
                'revision' => 1, 'auth_method' => 'pairing', 'qr' => 'data:image/png;base64,current', 'pairing_code' => '12345678']);
        });

        $this->assertSame('scan_qr', $manager->connect($provider, '6281234567890')->value);
        $this->assertSame('12345678', $manager->pairingCode($provider));
        $this->assertSame('data:image/png;base64,current', $manager->qrDataUri($provider));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/start')
            && $request['mode'] === 'pairing' && $request['phone'] === '6281234567890' && $request['generation'] === 1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/auth/qr'));
        $this->assertArrayNotHasKey('pairing_code', $provider->fresh()->session_meta);
        $this->assertArrayNotHasKey('qr', $provider->fresh()->session_meta);

        $obsolete = true;
        $this->assertNull($manager->qrDataUri($provider));
        $this->assertNull($manager->pairingCode($provider));
    }

    public function test_restart_outage_leaves_durable_stop_pending_and_never_starts_or_claims_success(): void
    {
        [$provider, $session] = $this->mapped();
        Http::fake(fn () => Http::response(['error' => 'offline'], 503));

        try {
            app(WhatsAppSessionManager::class)->restart($provider);
            $this->fail('Restart must wait for confirmed teardown.');
        } catch (WhatsAppEngineException $exception) {
            $this->assertSame('operation_pending', $exception->errorCode);
        }

        $this->assertSame('STOPPED', $session->fresh()->desired_state);
        $this->assertSame('stop', $session->fresh()->pendingOperation->action);
        $this->assertSame('pending', $session->fresh()->pendingOperation->status);
        $this->assertSame('unknown', $provider->fresh()->session_status);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/start'));
    }

    public function test_pending_disconnect_in_dashboard_is_not_announced_as_completed(): void
    {
        [$provider] = $this->mapped();
        Http::fake(fn () => Http::response(['error' => 'offline'], 503));

        Livewire::test(WhatsAppDevices::class)
            ->set('deviceId', $provider->id)
            ->call('disconnect')
            ->assertNotified('Operasi masih diproses')
            ->assertNotNotified('Perangkat diputus.');
    }

    public function test_legacy_webhooks_cannot_overwrite_mapped_authoritative_status(): void
    {
        [$provider, $session] = $this->mapped();
        $session->update(['status' => 'STOPPED', 'desired_state' => 'STOPPED', 'engine_available' => true, 'observed_at' => now()]);
        Http::fake();

        $handled = app(WhatsAppSessionManager::class)->handleWebhook($provider, [
            'event' => 'session.status', 'payload' => ['status' => 'WORKING', 'me' => ['id' => '628999999999@c.us']],
        ]);

        $this->assertTrue($handled);
        $this->assertSame('stopped', $provider->fresh()->session_status);
        $this->assertSame('STOPPED', $session->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_removed_mapping_never_falls_back_to_legacy_start(): void
    {
        [$provider, $session] = $this->mapped();
        $session->delete();
        Http::fake();

        try {
            app(WhatsAppSessionManager::class)->connect($provider);
            $this->fail('Deleted mapped sessions must not be recreated through the legacy runner.');
        } catch (WhatsAppEngineException $exception) {
            $this->assertSame('session_removed', $exception->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_unmapped_native_accounts_keep_the_existing_legacy_endpoints(): void
    {
        $provider = ProviderAccount::findOrFail($this->createProviderAccount('wag_hub', 'unmapped', [])['id']);
        Http::fake(fn (Request $request) => Http::response(['status' => $request->method() === 'DELETE' ? 'disconnected' : 'connected']));
        $manager = app(WhatsAppSessionManager::class);

        $this->assertSame('working', $manager->connect($provider)->value);
        $this->assertSame('stopped', $manager->disconnect($provider)->value);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'http://runner.test/sessions');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'http://runner.test/sessions/wgh-'.$provider->uuid);
        $this->assertDatabaseCount('engine_operations', 0);
    }

    public function test_admin_delete_keeps_legacy_record_until_mapped_teardown_is_confirmed(): void
    {
        [$provider, $session] = $this->mapped();
        $offline = true;
        Http::fake(function (Request $request) use (&$offline) {
            return $offline ? Http::response(['error' => 'offline'], 503)
                : Http::response(['ok' => true, 'canonical_status' => 'STOPPED', 'generation' => $request['generation'], 'revision' => 1]);
        });

        Livewire::test(ListProviderAccounts::class)->callTableAction('delete', $provider->id)
            ->assertNotified('Akun belum dihapus');
        $this->assertNotNull($provider->fresh());
        $this->assertFalse($provider->fresh()->trashed());
        $this->assertSame('delete', $session->fresh()->pendingOperation->action);

        $offline = false;
        Livewire::test(ListProviderAccounts::class)->callTableAction('delete', $provider->id)
            ->assertNotified('Akun provider dihapus');
        $this->assertSoftDeleted($provider);
        $this->assertSoftDeleted($session);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request['generation'] === 1);
    }

    public function test_admin_bulk_delete_also_tears_down_mapped_sessions(): void
    {
        [$provider, $session] = $this->mapped();
        Http::fake(fn (Request $request) => Http::response(['ok' => true, 'canonical_status' => 'STOPPED', 'generation' => $request['generation'], 'revision' => 1]));

        Livewire::test(ListProviderAccounts::class)->callTableBulkAction('delete', [$provider->id])
            ->assertNotified('Akun provider dihapus');
        $this->assertSoftDeleted($provider);
        $this->assertSoftDeleted($session);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request['generation'] === 1);
    }

    private function mapped(): array
    {
        $client = $this->createClientApplication(['engine:use']);
        $provider = ProviderAccount::findOrFail($this->createProviderAccount('wag_hub', 'mapped-native', [
            'owned_by_application_id' => $client['id'], 'engine_session_id' => 'legacy-ref',
        ])['id']);
        $this->artisan('engine:migrate-legacy')->assertSuccessful();

        return [$provider, EngineSession::sole()];
    }
}
