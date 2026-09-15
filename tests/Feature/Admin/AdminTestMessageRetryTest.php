<?php

namespace Tests\Feature\Admin;

use App\Domain\Delivery\AttachmentKind;
use App\Domain\Delivery\OutboundAttachment;
use App\Filament\Resources\GatewayMessages\Pages\ViewGatewayMessage;
use App\Jobs\DispatchGatewayMessage;
use App\Models\GatewayMessage;
use App\Models\MessageAttempt;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\GatewayMessageDispatcher;
use App\Services\ProviderAccountTester;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
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

    public function test_retry_runs_inline_when_global_dispatch_is_sync(): void
    {
        config(['gateway.dispatch' => 'sync']);

        $provider = $this->createProviderAccount('waha', 'waha-admin-sync-retry', [
            'base_url' => 'https://waha-admin-sync-retry.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);

        Http::fake([
            'waha-admin-sync-retry.test/*' => Http::sequence()
                ->push(['error' => 'temporary'], 503)
                ->push(['id' => 'waha-sync-retry-ok'], 201),
        ]);

        $first = app(ProviderAccountTester::class)->send(
            ProviderAccount::query()->findOrFail($provider['id']),
            '081234567890',
            'Pesan uji sync retry',
            1,
        );
        $this->assertFalse($first->success);

        $message = GatewayMessage::query()
            ->where('purpose', ProviderAccountTester::PURPOSE)
            ->sole();

        Livewire::test(ViewGatewayMessage::class, ['record' => $message->getKey()])
            ->assertActionVisible('retry')
            ->callAction('retry')
            ->assertNotified('Kirim ulang diproses');

        $message->refresh();
        $this->assertSame('provider_accepted', $message->status);
        $this->assertSame('waha-sync-retry-ok', $message->provider_message_id);
        $this->assertDatabaseCount('message_attempts', 2);
    }

    public function test_sync_retry_does_not_success_notify_when_delivery_fails(): void
    {
        config(['gateway.dispatch' => 'sync']);

        $provider = $this->createProviderAccount('waha', 'waha-admin-sync-retry-fail', [
            'base_url' => 'https://waha-admin-sync-retry-fail.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);

        Http::fake([
            'waha-admin-sync-retry-fail.test/*' => Http::sequence()
                ->push(['error' => 'temporary'], 503)
                ->push(['error' => 'still down'], 503),
        ]);

        $first = app(ProviderAccountTester::class)->send(
            ProviderAccount::query()->findOrFail($provider['id']),
            '081234567890',
            'Pesan uji sync retry gagal',
            1,
        );
        $this->assertFalse($first->success);

        $message = GatewayMessage::query()
            ->where('purpose', ProviderAccountTester::PURPOSE)
            ->sole();

        Livewire::test(ViewGatewayMessage::class, ['record' => $message->getKey()])
            ->assertActionVisible('retry')
            ->callAction('retry')
            ->assertNotified('Kirim ulang selesai dengan status outcome_unknown');

        $message->refresh();
        $this->assertSame('outcome_unknown', $message->status);
        $this->assertDatabaseCount('message_attempts', 2);
        $this->assertDatabaseMissing('message_events', [
            'gateway_message_id' => $message->getKey(),
            'type' => 'enqueue_failed',
        ]);
    }

    public function test_retrying_an_admin_attachment_test_resends_through_the_media_path(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-admin-attach-retry', [
            'base_url' => 'https://waha-admin-attach-retry.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        $publicUrl = 'https://cdn.example.com/uji/retry.png';

        Http::fake([
            'waha-admin-attach-retry.test/*' => Http::sequence()
                ->push(['error' => 'temporary'], 503)
                ->push(['id' => 'waha-attach-retry-ok'], 201),
        ]);

        $first = app(ProviderAccountTester::class)->send(
            ProviderAccount::query()->findOrFail($provider['id']),
            '081234567890',
            'Caption retry lampiran',
            1,
            new OutboundAttachment(
                kind: AttachmentKind::Image,
                url: $publicUrl,
            ),
        );
        $this->assertFalse($first->success);

        $message = GatewayMessage::query()
            ->where('purpose', ProviderAccountTester::PURPOSE)
            ->sole();
        $this->assertSame('outcome_unknown', $message->status);
        $this->assertSame('image', $message->message_type);
        $this->assertSame($publicUrl, $message->outboundAttachment()?->url);

        config(['gateway.dispatch' => 'sync']);

        Livewire::test(ViewGatewayMessage::class, ['record' => $message->getKey()])
            ->assertActionVisible('retry')
            ->callAction('retry')
            ->assertNotified('Kirim ulang diproses');

        $message->refresh();
        $this->assertSame('provider_accepted', $message->status);
        $this->assertSame('waha-attach-retry-ok', $message->provider_message_id);
        $this->assertDatabaseCount('message_attempts', 2);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://waha-admin-attach-retry.test/api/sendImage'
            && ($request['file']['url'] ?? null) === $publicUrl
            && ($request['caption'] ?? null) === 'Caption retry lampiran'
            && ! isset($request['text']));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sendText'));
    }
}
