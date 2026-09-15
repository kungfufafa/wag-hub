<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\WhatsAppInbox;
use App\Models\GatewayMessage;
use App\Models\InboxMessage;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\WhatsAppInbox as InboxService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

final class WhatsAppInboxAttachmentTest extends TestCase
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function wrappingDrivers(): array
    {
        return [
            'waha' => ['waha'],
            'fonnte' => ['fonnte'],
            'gowa' => ['gowa'],
            'waba' => ['waba'],
        ];
    }

    #[DataProvider('wrappingDrivers')]
    public function test_inbox_upload_send_hits_the_wrapping_driver_media_contract(string $driver): void
    {
        Storage::fake('local');
        $provider = $this->createWrappingAccount($driver);
        $this->fakeSuccessfulImageSend($driver);

        Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->call('startNewChat')
            ->set('newRecipient', '081234567890')
            ->set('attachmentKind', 'image')
            ->set('attachmentFile', UploadedFile::fake()->image('dashboard.png', 80, 80))
            ->call('send')
            ->assertNotified('Pesan terkirim.')
            ->assertSet('attachmentFile', null);

        $stored = InboxMessage::query()->firstOrFail();
        $gateway = GatewayMessage::query()->where('origin', 'inbox')->firstOrFail();
        $this->assertSame('provider_accepted', $gateway->status);
        $this->assertSame('image', $gateway->message_type);
        $this->assertSame($provider['id'], $gateway->pinned_provider_account_id);
        $this->assertTrue($stored->from_me);
        $this->assertSame('image', $stored->kind);
        $this->assertSame('dashboard.png', $stored->attachment['filename']);
        $this->assertNotNull($stored->attachment['id']);
        $this->assertSame($stored->attachment['id'], $gateway->outboundAttachment()?->attachmentId);

        $this->assertImageContract($driver, function (string $url) use ($stored): bool {
            return preg_match('#^https?://#i', $url) === 1
                && ! str_contains($url, 'attachment://')
                && str_contains($url, (string) $stored->attachment['id']);
        });
        $this->assertNoTextOnlyFallback($driver);
        $this->assertSame('healthy', ProviderAccount::query()->findOrFail($provider['id'])->health_status);
    }

    #[DataProvider('wrappingDrivers')]
    public function test_inbox_public_url_send_hits_the_wrapping_driver_media_contract(string $driver): void
    {
        $provider = $this->createWrappingAccount($driver);
        $this->fakeSuccessfulImageSend($driver);
        $publicUrl = 'https://cdn.example.com/inbox/photo.jpg';

        Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->call('startNewChat')
            ->set('newRecipient', '081234567890')
            ->set('attachmentKind', 'image')
            ->set('attachmentUrl', $publicUrl)
            ->set('draft', 'Lihat foto')
            ->call('send')
            ->assertNotified('Pesan terkirim.')
            ->assertSet('attachmentUrl', '');

        $stored = InboxMessage::query()->firstOrFail();
        $gateway = GatewayMessage::query()->where('origin', 'inbox')->firstOrFail();
        $this->assertSame('provider_accepted', $gateway->status);
        $this->assertSame('image', $gateway->message_type);
        $this->assertSame($provider['id'], $gateway->pinned_provider_account_id);
        $this->assertSame('Lihat foto', $gateway->plaintextBody());
        $this->assertSame($publicUrl, $gateway->outboundAttachment()?->url);
        $this->assertTrue($stored->from_me);
        $this->assertSame('image', $stored->kind);
        $this->assertSame($publicUrl, $stored->attachment['url']);

        $this->assertImageContract($driver, fn (string $url): bool => $url === $publicUrl, caption: 'Lihat foto');
        $this->assertNoTextOnlyFallback($driver);
    }

    public function test_inbox_shows_an_attachment_error_instead_of_sending_caption_only(): void
    {
        $provider = $this->createWrappingAccount('fonnte');
        Http::fake();

        Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->call('startNewChat')
            ->set('newRecipient', '081234567890')
            ->set('attachmentKind', 'audio')
            ->set('attachmentUrl', 'https://cdn.example.com/voice/greeting.mp3')
            ->set('draft', 'Caption yang tidak boleh ikut')
            ->call('send')
            ->assertNotified('Audio tidak mendukung caption.')
            ->assertSet('attachmentError', 'Audio tidak mendukung caption.');

        $this->assertDatabaseCount('gateway_messages', 0);
        Http::assertNothingSent();
    }

    public function test_repeating_a_dashboard_submission_uuid_does_not_send_a_second_message(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-inbox-idempotent', [
            'base_url' => 'https://waha-inbox-idempotent.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);

        Http::fake([
            'waha-inbox-idempotent.test/*' => Http::response(['id' => 'out-text-1'], 201),
        ]);

        $inbox = app(InboxService::class);
        $submissionUuid = (string) Str::uuid();
        $account = ProviderAccount::query()->findOrFail($provider['id']);

        $first = $inbox->send($account, '081234567890', 'Pesan yang sama', submissionUuid: $submissionUuid);
        $second = $inbox->send($account, '081234567890', 'Pesan yang sama', submissionUuid: $submissionUuid);

        $this->assertTrue($first->isAccepted());
        $this->assertTrue($second->isAccepted());
        $this->assertDatabaseCount('gateway_messages', 1);
        $this->assertDatabaseCount('inbox_messages', 1);
        Http::assertSentCount(1);
    }

    public function test_clearing_a_failed_attachment_lets_the_remaining_caption_send(): void
    {
        Storage::fake('local');
        $provider = $this->createProviderAccount('waha', 'waha-inbox-clear-attachment', [
            'base_url' => 'https://waha-inbox-clear-attachment.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'sendImage')) {
                return Http::response(['error' => 'temporary'], 503);
            }

            if (str_contains($url, 'sendText')) {
                return Http::response(['id' => 'waha-caption-ok'], 201);
            }

            return Http::response([]);
        });

        $page = Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->call('startNewChat')
            ->set('newRecipient', '081234567890')
            ->set('attachmentKind', 'image')
            ->set('attachmentFile', UploadedFile::fake()->image('dashboard.png', 80, 80))
            ->set('draft', 'Caption tersisa')
            ->call('send')
            ->assertNotified('Pesan belum terkirim');

        $failedUuid = $page->get('submissionUuid');
        $this->assertNotSame('', $failedUuid);
        $this->assertNotNull($page->get('storedAttachmentId'));
        $this->assertSame('Caption tersisa', $page->get('draft'));

        $page->call('clearAttachment')
            ->assertSet('attachmentFile', null)
            ->assertSet('storedAttachmentId', null)
            ->assertSet('attachmentUrl', '');

        $this->assertNotSame($failedUuid, $page->get('submissionUuid'));

        $page->call('send')
            ->assertNotified('Pesan terkirim.')
            ->assertSet('draft', '');

        $text = GatewayMessage::query()
            ->where('origin', 'inbox')
            ->where('message_type', 'text')
            ->sole();
        $this->assertSame('provider_accepted', $text->status);
        $this->assertSame('Caption tersisa', $text->plaintextBody());
        $this->assertNull($text->outboundAttachment());
        $this->assertTrue(
            InboxMessage::query()->where('kind', 'text')->where('from_me', true)->exists(),
        );
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendText')
            && $request['text'] === 'Caption tersisa');
    }

    /**
     * @return array{id: int, slug: string}
     */
    private function createWrappingAccount(string $driver): array
    {
        return match ($driver) {
            'waha' => $this->createProviderAccount('waha', 'waha-inbox-attachment', [
                'base_url' => 'https://waha-inbox-attachment.test',
                'session' => 'default',
                'api_key' => 'waha-secret',
            ]),
            'fonnte' => $this->createProviderAccount('fonnte', 'fonnte-inbox-attachment', [
                'endpoint' => 'https://fonnte-inbox-attachment.test/send',
                'token' => 'fonnte-secret',
            ]),
            'gowa' => $this->createProviderAccount('gowa', 'gowa-inbox-attachment', [
                'base_url' => 'https://gowa-inbox-attachment.test',
                'username' => 'gateway',
                'password' => 'gowa-secret',
                'version' => '8.10.0',
            ]),
            'waba' => $this->createProviderAccount('waba', 'waba-inbox-attachment', [
                'base_url' => 'https://graph-waba-inbox-attachment.test',
                'api_version' => 'v25.0',
                'phone_number_id' => '123456789012345',
                'access_token' => 'meta-system-user-token',
            ]),
            default => $this->fail('Unknown wrapping driver '.$driver),
        };
    }

    private function fakeSuccessfulImageSend(string $driver): void
    {
        Http::fake(function (Request $request) use ($driver) {
            $url = $request->url();

            return match ($driver) {
                'waha' => str_contains($url, 'sendImage')
                    ? Http::response(['id' => 'waha-inbox-img'], 201)
                    : Http::response([]),
                'fonnte' => str_contains($url, '/send')
                    ? Http::response(['status' => true, 'id' => ['fonnte-inbox-img'], 'process' => 'pending'])
                    : Http::response([]),
                'gowa' => str_contains($url, '/send/image')
                    ? Http::response([
                        'code' => 'SUCCESS',
                        'message' => 'Success',
                        'results' => ['message_id' => 'gowa-inbox-img', 'status' => 'sent'],
                    ])
                    : Http::response([]),
                'waba' => str_contains($url, '/messages')
                    ? Http::response([
                        'messaging_product' => 'whatsapp',
                        'contacts' => [['wa_id' => '6281234567890']],
                        'messages' => [['id' => 'wamid.inbox-img']],
                    ])
                    : Http::response([]),
                default => Http::response([], 404),
            };
        });
    }

    /**
     * @param  callable(string): bool  $urlMatches
     */
    private function assertImageContract(string $driver, callable $urlMatches, ?string $caption = null): void
    {
        Http::assertSent(function (Request $request) use ($driver, $urlMatches, $caption): bool {
            $mediaUrl = match ($driver) {
                'waha' => is_array($request['file'] ?? null) ? ($request['file']['url'] ?? null) : null,
                'fonnte' => $this->multipartValue($request, 'url'),
                'gowa' => $this->multipartValue($request, 'image_url'),
                'waba' => is_array($request['image'] ?? null) ? ($request['image']['link'] ?? null) : null,
                default => null,
            };

            if (! is_string($mediaUrl) || ! $urlMatches($mediaUrl)) {
                return false;
            }

            $captionMatches = match ($driver) {
                'waha' => $caption === null ? ! isset($request['caption']) : ($request['caption'] ?? null) === $caption,
                'fonnte' => $caption === null
                    ? ! $request->hasFile('message')
                    : $request->hasFile('message', $caption),
                'gowa' => $caption === null
                    ? $this->multipartValue($request, 'caption') === null
                    : $this->multipartValue($request, 'caption') === $caption,
                'waba' => $caption === null
                    ? ! isset($request['image']['caption'])
                    : ($request['image']['caption'] ?? null) === $caption,
                default => false,
            };

            if (! $captionMatches) {
                return false;
            }

            return match ($driver) {
                'waha' => str_contains($request->url(), '/api/sendImage') && ! isset($request['text']),
                'fonnte' => $request->url() === 'https://fonnte-inbox-attachment.test/send'
                    && $request->isMultipart()
                    && $request->hasFile('target', '6281234567890'),
                'gowa' => $request->url() === 'https://gowa-inbox-attachment.test/send/image'
                    && $request->isMultipart()
                    && $this->multipartValue($request, 'phone') === '6281234567890@s.whatsapp.net',
                'waba' => $request->url() === 'https://graph-waba-inbox-attachment.test/v25.0/123456789012345/messages'
                    && ($request['type'] ?? null) === 'image'
                    && ! isset($request['text']),
                default => false,
            };
        });
    }

    private function assertNoTextOnlyFallback(string $driver): void
    {
        Http::assertNotSent(function (Request $request) use ($driver): bool {
            return match ($driver) {
                'waha' => str_contains($request->url(), 'sendText'),
                'gowa' => str_contains($request->url(), '/send/message'),
                'waba' => ($request['type'] ?? null) === 'text',
                'fonnte' => $request->isMultipart()
                    && $request->hasFile('message')
                    && ! $request->hasFile('url'),
                default => false,
            };
        });
    }

    private function multipartValue(Request $request, string $name): ?string
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name && is_string($part['contents'] ?? null)) {
                return $part['contents'];
            }
        }

        return null;
    }
}
