<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\GatewayMessages\Pages\ViewGatewayMessage;
use App\Jobs\DispatchGatewayMessage;
use App\Models\GatewayMessage;
use App\Models\MessageAttempt;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\GatewayMessageDispatcher;
use App\Services\ProviderAccountTester;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

final class AdminTestMessageRetryTest extends TestCase
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

    public function test_retrying_an_admin_test_message_resends_through_the_original_provider(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-admin-retry', [
            'base_url' => 'https://waha-admin-retry.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);

        Http::fake([
            'waha-admin-retry.test/*' => Http::sequence()
                ->push(['error' => 'temporary'], 503)
                ->push(['id' => 'waha-retry-ok'], 201),
        ]);

        $first = app(ProviderAccountTester::class)->send(
            ProviderAccount::query()->findOrFail($provider['id']),
            '081234567890',
            'Pesan uji untuk retry',
            1,
        );
        $this->assertFalse($first->success);

        $message = GatewayMessage::query()
            ->where('purpose', ProviderAccountTester::PURPOSE)
            ->sole();
        $this->assertSame('outcome_unknown', $message->status);

        Queue::fake();

        Livewire::test(ViewGatewayMessage::class, ['record' => $message->getKey()])
            ->assertActionVisible('retry')
            ->callAction('retry')
            ->assertNotified('Kirim ulang masuk antrian');

        $this->assertSame('queued', $message->fresh()->status);
        Queue::assertPushed(DispatchGatewayMessage::class);

        (new DispatchGatewayMessage($message->getKey()))->handle(
            app(GatewayMessageDispatcher::class),
        );

        $message->refresh();
        $this->assertSame('provider_accepted', $message->status);
        $this->assertSame($provider['id'], $message->accepted_provider_account_id);
        $this->assertSame('waha-retry-ok', $message->provider_message_id);
        $this->assertDatabaseCount('message_attempts', 2);
        $this->assertSame(
            'accepted',
            MessageAttempt::query()->where('gateway_message_id', $message->getKey())->orderByDesc('sequence')->value('status'),
        );
    }
}
