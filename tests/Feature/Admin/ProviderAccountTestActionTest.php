<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\ProviderAccounts\Pages\ListProviderAccounts;
use App\Models\GatewayMessage;
use App\Models\NumberCheckRequest;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\ProviderAccountTester;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

final class ProviderAccountTestActionTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]));
    }

    public function test_admin_can_send_a_test_message_from_the_provider_list(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-admin-test', [
            'base_url' => 'https://waha-admin-test.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        Http::fake([
            'waha-admin-test.test/*' => Http::response(['id' => 'waha-test-1'], 201),
        ]);

        Livewire::test(ListProviderAccounts::class)
            ->callTableAction('test', $provider['id'], data: [
                'type' => 'send',
                'recipient' => '081234567890',
                'body' => 'Pesan uji admin',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Uji kirim berhasil');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://waha-admin-test.test/api/sendText'
            && $request['chatId'] === '6281234567890@c.us'
            && $request['text'] === 'Pesan uji admin');

        $message = GatewayMessage::query()->where('purpose', ProviderAccountTester::PURPOSE)->sole();
        $this->assertSame(ProviderAccountTester::ROUTE_KEY, $message->route_key);
        $this->assertSame('provider_accepted', $message->status);
        $this->assertSame($provider['id'], $message->accepted_provider_account_id);
        $this->assertDatabaseHas('message_attempts', [
            'gateway_message_id' => $message->getKey(),
            'provider_account_id' => $provider['id'],
            'status' => 'accepted',
            'sequence' => 1,
        ]);
        $this->assertDatabaseHas('client_applications', [
            'slug' => ProviderAccountTester::CLIENT_SLUG,
        ]);

        $freshProvider = ProviderAccount::query()->findOrFail($provider['id']);
        $this->assertSame('healthy', $freshProvider->health_status);
        $this->assertSame(0, $freshProvider->consecutive_failures);
    }

    public function test_admin_can_check_a_number_from_the_provider_list(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-admin-check', [
            'base_url' => 'https://waha-admin-check.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        Http::fake([
            'waha-admin-check.test/*' => Http::response([
                'numberExists' => true,
                'chatId' => '6281234567890@c.us',
            ]),
        ]);

        Livewire::test(ListProviderAccounts::class)
            ->callTableAction('test', $provider['id'], data: [
                'type' => 'check_number',
                'recipient' => '6281234567890',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Uji cek nomor berhasil');

        $audit = NumberCheckRequest::query()
            ->where('route_key', ProviderAccountTester::ROUTE_KEY)
            ->sole();
        $this->assertSame('registered', $audit->status);
        $this->assertTrue($audit->registered);
        $this->assertSame($provider['id'], $audit->resolved_provider_account_id);
        $this->assertDatabaseHas('number_check_attempts', [
            'number_check_request_id' => $audit->getKey(),
            'provider_account_id' => $provider['id'],
            'status' => 'registered',
            'sequence' => 1,
        ]);
    }

    public function test_failed_send_test_updates_provider_health(): void
    {
        config()->set('gateway.provider_health.failure_threshold', 1);
        config()->set('gateway.provider_health.circuit_open_seconds', 300);

        $provider = $this->createProviderAccount('waha', 'waha-admin-fail', [
            'base_url' => 'https://waha-admin-fail.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        Http::fake([
            'waha-admin-fail.test/*' => Http::response(['error' => 'down'], 503),
        ]);

        Livewire::test(ListProviderAccounts::class)
            ->callTableAction('test', $provider['id'], data: [
                'type' => 'send',
                'recipient' => '081234567890',
                'body' => 'Pesan uji gagal',
            ])
            ->assertNotified('Uji kirim gagal');

        $freshProvider = ProviderAccount::query()->findOrFail($provider['id']);
        $this->assertSame('unavailable', $freshProvider->health_status);
        $this->assertSame(1, $freshProvider->consecutive_failures);
        $this->assertNotNull($freshProvider->circuit_open_until);

        $message = GatewayMessage::query()->where('purpose', ProviderAccountTester::PURPOSE)->sole();
        $this->assertSame('outcome_unknown', $message->status);
    }

    public function test_waba_number_check_test_is_unsupported_and_skips_health_penalty(): void
    {
        $account = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waba', 'waba-admin-test', [
                'base_url' => 'https://graph-admin-test.test',
                'api_version' => 'v25.0',
                'phone_number_id' => '123456789012345',
                'access_token' => 'meta-token',
            ])['id'],
        );
        $account->forceFill([
            'health_status' => 'healthy',
            'consecutive_failures' => 0,
        ])->save();

        $result = app(ProviderAccountTester::class)->checkNumber($account, '081234567890', 1);

        $this->assertFalse($result->success);
        $this->assertSame('Cek nomor tidak didukung', $result->title);

        $audit = NumberCheckRequest::query()
            ->where('route_key', ProviderAccountTester::ROUTE_KEY)
            ->sole();
        $this->assertSame('unsupported', $audit->status);

        $account->refresh();
        $this->assertSame('healthy', $account->health_status);
        $this->assertSame(0, $account->consecutive_failures);
    }
}
