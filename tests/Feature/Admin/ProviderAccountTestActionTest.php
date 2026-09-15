<?php

namespace Tests\Feature\Admin;

use App\Domain\Delivery\AttachmentKind;
use App\Domain\Delivery\OutboundAttachment;
use App\Filament\Resources\ProviderAccounts\Pages\ListProviderAccounts;
use App\Models\Attachment;
use App\Models\GatewayMessage;
use App\Models\NumberCheckRequest;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\ProviderAccountTester;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
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
        $this->assertSame('text', $message->message_type);
        $this->assertNull($message->outboundAttachment());
        $this->assertSame($provider['id'], $message->accepted_provider_account_id);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sendImage'));
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

    public function test_rejected_send_test_does_not_penalize_provider_health(): void
    {
        config()->set('gateway.provider_health.failure_threshold', 1);

        $provider = $this->createProviderAccount('waha', 'waha-admin-reject', [
            'base_url' => 'https://waha-admin-reject.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        Http::fake([
            'waha-admin-reject.test/*' => Http::response(['error' => 'Invalid chatId'], 422),
        ]);

        Livewire::test(ListProviderAccounts::class)
            ->callTableAction('test', $provider['id'], data: [
                'type' => 'send',
                'recipient' => '081234567890',
                'body' => 'Pesan uji reject',
            ])
            ->assertNotified('Uji kirim gagal');

        $freshProvider = ProviderAccount::query()->findOrFail($provider['id']);
        $this->assertSame('healthy', $freshProvider->health_status);
        $this->assertSame(0, $freshProvider->consecutive_failures);
        $this->assertNull($freshProvider->circuit_open_until);

        $message = GatewayMessage::query()->where('purpose', ProviderAccountTester::PURPOSE)->sole();
        $this->assertSame('failed', $message->status);
        $this->assertSame('invalid_message', $message->last_error_code);
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

    public function test_admin_can_send_an_attachment_url_from_the_provider_test_action(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-admin-attach-url', [
            'base_url' => 'https://waha-admin-attach-url.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        Http::fake([
            'waha-admin-attach-url.test/*' => Http::response(['id' => 'waha-img-test-1'], 201),
        ]);
        $publicUrl = 'https://cdn.example.com/uji/banner.png';

        Livewire::test(ListProviderAccounts::class)
            ->callTableAction('test', $provider['id'], data: [
                'type' => 'send',
                'recipient' => '081234567890',
                'body' => 'Caption uji lampiran',
                'attachment_kind' => 'image',
                'attachment_url' => $publicUrl,
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Uji kirim berhasil');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://waha-admin-attach-url.test/api/sendImage'
            && $request['chatId'] === '6281234567890@c.us'
            && $request['caption'] === 'Caption uji lampiran'
            && $request['file'] === [
                'mimetype' => 'image/png',
                'url' => $publicUrl,
                'filename' => 'banner.png',
            ]
            && ! isset($request['text']));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sendText'));

        $message = GatewayMessage::query()->where('purpose', ProviderAccountTester::PURPOSE)->sole();
        $this->assertSame('provider_accepted', $message->status);
        $this->assertSame('image', $message->message_type);
        $this->assertSame('Caption uji lampiran', $message->plaintextBody());
        $this->assertSame($publicUrl, $message->outboundAttachment()?->url);
        $this->assertSame('waha-img-test-1', $message->provider_message_id);
        $this->assertSame('healthy', ProviderAccount::query()->findOrFail($provider['id'])->health_status);
    }

    public function test_admin_can_upload_an_attachment_from_the_provider_test_action(): void
    {
        Storage::fake('local');
        $provider = $this->createProviderAccount('waha', 'waha-admin-attach-file', [
            'base_url' => 'https://waha-admin-attach-file.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        Http::fake([
            'waha-admin-attach-file.test/*' => Http::response(['id' => 'waha-img-upload-1'], 201),
        ]);

        Livewire::test(ListProviderAccounts::class)
            ->callTableAction('test', $provider['id'], data: [
                'type' => 'send',
                'recipient' => '081234567890',
                'body' => 'Caption unggahan',
                'attachment_file' => UploadedFile::fake()->image('dashboard.png', 80, 80),
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Uji kirim berhasil');

        $message = GatewayMessage::query()->where('purpose', ProviderAccountTester::PURPOSE)->sole();
        $this->assertSame('image', $message->message_type);
        $this->assertSame('Caption unggahan', $message->plaintextBody());
        $attachmentId = $message->outboundAttachment()?->attachmentId;
        $this->assertNotNull($attachmentId);

        $stored = Attachment::query()->where('uuid', $attachmentId)->firstOrFail();
        $this->assertSame('active', $stored->status);
        $this->assertSame('dashboard.png', $stored->original_filename);
        $this->assertNotNull($stored->last_referenced_at);
        $this->assertNotNull($stored->expires_at);
        $this->assertSame(auth()->id(), $stored->user_id);

        Http::assertSent(function (Request $request) use ($attachmentId): bool {
            $url = is_array($request['file'] ?? null) ? ($request['file']['url'] ?? null) : null;

            return $request->url() === 'https://waha-admin-attach-file.test/api/sendImage'
                && is_string($url)
                && str_contains($url, (string) $attachmentId)
                && ! str_contains($url, 'attachment://')
                && ($request['caption'] ?? null) === 'Caption unggahan'
                && ! isset($request['text']);
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sendText'));
    }

    public function test_admin_can_send_an_attachment_without_caption(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-admin-attach-empty', [
            'base_url' => 'https://waha-admin-attach-empty.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        Http::fake([
            'waha-admin-attach-empty.test/*' => Http::response(['id' => 'waha-img-empty-1'], 201),
        ]);

        Livewire::test(ListProviderAccounts::class)
            ->callTableAction('test', $provider['id'], data: [
                'type' => 'send',
                'recipient' => '081234567890',
                'body' => '',
                'attachment_kind' => 'image',
                'attachment_url' => 'https://cdn.example.com/uji/photo.jpg',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Uji kirim berhasil');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://waha-admin-attach-empty.test/api/sendImage'
            && ! isset($request['caption'])
            && ! isset($request['text']));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sendText'));
    }

    public function test_audio_caption_is_rejected_before_the_provider_is_called(): void
    {
        $provider = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-admin-audio', [
                'base_url' => 'https://waha-admin-audio.test',
                'session' => 'default',
                'api_key' => 'waha-secret',
            ])['id'],
        );
        Http::fake();

        try {
            app(ProviderAccountTester::class)->send(
                $provider,
                '081234567890',
                'Caption yang tidak boleh ikut',
                auth()->id(),
                new OutboundAttachment(
                    kind: AttachmentKind::Audio,
                    url: 'https://cdn.example.com/voice/greeting.ogg',
                    mimeType: 'audio/ogg; codecs=opus',
                ),
            );
            $this->fail('Audio caption should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Audio tidak mendukung caption.', $exception->getMessage());
        }

        $this->assertDatabaseCount('gateway_messages', 0);
        Http::assertNothingSent();
    }
}
