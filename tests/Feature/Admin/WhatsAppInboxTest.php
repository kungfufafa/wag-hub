<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\WhatsAppInbox;
use App\Filament\Resources\ProviderAccounts\Pages\ListProviderAccounts;
use App\Jobs\DispatchGatewayMessage;
use App\Models\GatewayMessage;
use App\Models\InboxMessage;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\GatewayMessageDispatcher;
use App\Services\WhatsAppInbox as InboxService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class WhatsAppInboxTest extends TestCase
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

    public function test_an_administrator_can_open_the_inbox_page(): void
    {
        $html = $this->get('/panel/inbox')
            ->assertOk()
            ->assertSee('wa-inbox', false)
            ->assertSee('Percakapan')
            ->assertSee('Cari nama, nomor, atau akun')
            ->assertSee('Tulis pesan')
            ->assertSee('Kirim')
            ->assertSee('Obrolan baru')
            ->assertSee('Muat ulang')
            ->assertSee('Kirim dan baca chat langsung dari akun WAHA, GOWA, Fonnte, dan WABA.')
            ->assertDontSee('>Kanal<', false)
            ->getContent();

        $this->assertIdleComposerIsCompact($html);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*wa-inbox-thread-title[^"]*"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/wa-inbox-thread-name[^>]*>\s*Pilih percakapan\s*</',
            $html,
        );
    }

    public function test_idle_composer_keeps_lampiran_collapsed_until_an_attachment_is_chosen(): void
    {
        $page = Livewire::test(WhatsAppInbox::class);

        $this->assertIdleComposerIsCompact($page->html());

        $open = $page
            ->set('attachmentUrl', 'https://cdn.example.com/inbox/photo.jpg')
            ->html();

        $this->assertMatchesRegularExpression(
            '/id="wa-inbox-attachment-panel"[^>]*data-expanded="true"/',
            $open,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="wa-inbox-attachment-panel"[^>]*data-expanded="false"/',
            $open,
        );
        $this->assertStringContainsString('aria-label="Jenis lampiran"', $open);
        $this->assertStringContainsString('Atau URL publik HTTP(S) lampiran', $open);
        $this->assertStringContainsString('type="file"', $open);
    }

    public function test_waha_inbox_lists_inbound_and_outbound_then_sends_a_reply(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-inbox', [
            'base_url' => 'https://waha-inbox.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'chats/overview')) {
                return Http::response([
                    [
                        'id' => '6281234567890@c.us',
                        'name' => 'Budi',
                        'lastMessage' => [
                            'id' => 'in-1',
                            'body' => 'Halo Hub',
                            'fromMe' => false,
                            'timestamp' => 1_700_000_000,
                        ],
                    ],
                ]);
            }

            if (str_contains($url, '/messages')) {
                return Http::response([
                    [
                        'id' => 'in-1',
                        'body' => 'Halo Hub',
                        'fromMe' => false,
                        'timestamp' => 1_700_000_000,
                    ],
                    [
                        'id' => 'out-1',
                        'body' => 'Siap',
                        'fromMe' => true,
                        'timestamp' => 1_700_000_060,
                    ],
                    [
                        'id' => [
                            'fromMe' => false,
                            'remote' => '6281234567890@c.us',
                            'id' => 'in-nested',
                        ],
                        'key' => [
                            'fromMe' => false,
                            'remoteJid' => '6281234567890@c.us',
                        ],
                        'message' => [
                            'conversation' => 'Balasan pelanggan',
                        ],
                        'messageTimestamp' => 1_700_000_120,
                    ],
                ]);
            }

            if (str_contains($url, 'sendText')) {
                return Http::response(['id' => 'waha-sent'], 201);
            }

            return Http::response([], 404);
        });

        $page = Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->assertSee('Budi')
            ->assertSee('Halo Hub')
            ->assertSee('wa-inbox-item-preview', false)
            ->assertSee('wa-inbox-item-foot', false)
            ->call('selectChat', '6281234567890@c.us', 'Budi')
            ->assertSee('Siap')
            ->assertSee('Balasan pelanggan')
            ->assertSee('wa-bubble-text', false)
            ->assertSee('wa-bubble is-in', false)
            ->assertSee('wa-bubble is-out', false)
            ->set('draft', 'Balasan dari Hub')
            ->call('send')
            ->assertNotified('Pesan terkirim.')
            ->assertSee('Balasan dari Hub');

        $this->assertMatchesRegularExpression(
            '/wa-inbox-thread-name[^>]*>\s*Budi\s*</',
            $page->html(),
        );

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendText')
            && $request['text'] === 'Balasan dari Hub'
            && $request['chatId'] === '6281234567890@c.us');

        $this->assertNotEmpty($page->get('messages'));
        $this->assertTrue(collect($page->get('messages'))->contains(
            fn (array $message): bool => $message['from_me'] === false && $message['body'] === 'Balasan pelanggan',
        ));
        $this->assertTrue(collect($page->get('messages'))->contains(
            fn (array $message): bool => $message['from_me'] === true && $message['body'] === 'Balasan dari Hub',
        ));
    }

    public function test_sent_messages_stay_visible_when_the_gateway_history_is_empty(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-empty-history', [
            'base_url' => 'https://waha-empty.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'sendText')) {
                return Http::response(['id' => 'waha-sent'], 201);
            }

            return Http::response([]);
        });

        Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->call('startNewChat')
            ->set('newRecipient', '081234567890')
            ->set('draft', 'Halo dari Hub')
            ->call('send')
            ->assertNotified('Pesan terkirim.')
            ->assertSet('chatId', '6281234567890@c.us')
            ->assertSee('Halo dari Hub')
            ->assertSee('wa-bubble-text', false);

        $this->assertSame(1, InboxMessage::query()->count());
        $this->assertTrue(InboxMessage::query()->first()->from_me);
    }

    public function test_retrying_a_failed_inbox_submission_enqueues_delivery_again(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-inbox-retry', [
            'base_url' => 'https://waha-inbox-retry.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);

        Http::fake([
            'waha-inbox-retry.test/*' => Http::sequence()
                ->push(['error' => 'invalid'], 422)
                ->push(['id' => 'waha-inbox-retry-ok'], 201),
        ]);

        $inbox = app(InboxService::class);
        $submissionUuid = (string) Str::uuid();
        $account = ProviderAccount::query()->findOrFail($provider['id']);

        $first = $inbox->send($account, '081234567890', 'Pesan yang gagal', submissionUuid: $submissionUuid);
        $this->assertFalse($first->isAccepted());

        $message = GatewayMessage::query()->where('origin', 'inbox')->sole();
        $this->assertTrue($message->isSafeToRetry());

        Queue::fake();

        $second = $inbox->send($account, '081234567890', 'Pesan yang gagal', submissionUuid: $submissionUuid);

        Queue::assertPushed(
            DispatchGatewayMessage::class,
            fn (DispatchGatewayMessage $job): bool => $job->messageId === (int) $message->id,
        );
        $this->assertSame('queued', $message->fresh()->status);
        $this->assertSame('message_queued', $second->result->errorCode);

        (new DispatchGatewayMessage((int) $message->id))->handle(
            app(GatewayMessageDispatcher::class),
        );

        $this->assertSame('provider_accepted', $message->fresh()->status);
        $this->assertSame('waha-inbox-retry-ok', $message->fresh()->provider_message_id);
        $this->assertDatabaseCount('gateway_messages', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendText')
            && $request['text'] === 'Pesan yang gagal');
    }

    public function test_gowa_inbox_lists_chats_from_the_gateway(): void
    {
        $provider = $this->createProviderAccount('gowa', 'gowa-inbox', [
            'base_url' => 'https://gowa-inbox.test',
            'username' => 'gateway',
            'password' => 'secret',
            'device_id' => 'device-1',
        ]);

        Http::fake([
            'gowa-inbox.test/chats*' => Http::response([
                'code' => 'SUCCESS',
                'results' => [
                    'data' => [
                        [
                            'jid' => '6281111111111@s.whatsapp.net',
                            'name' => 'Sari',
                            'last_message' => 'Ping',
                            'last_message_time' => '2024-01-15T10:30:00Z',
                        ],
                    ],
                ],
            ]),
        ]);

        Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->assertSee('Sari')
            ->assertSee('Ping');
    }

    public function test_fonnte_inbox_uses_the_same_wrapping_workspace(): void
    {
        $provider = $this->createProviderAccount('fonnte', 'fonnte-inbox', [
            'endpoint' => 'https://api.fonnte.com/send',
            'token' => 'fonnte-secret',
        ]);

        $page = Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->assertSee('Belum ada percakapan')
            ->assertDontSee('tidak menarik riwayat')
            ->call('startNewChat')
            ->assertSet('composingNew', true)
            ->assertSee('Nomor tujuan')
            ->assertSee('Obrolan baru');

        $this->assertMatchesRegularExpression(
            '/wa-inbox-thread-name[^>]*>\s*Obrolan baru\s*</',
            $page->html(),
        );
    }

    public function test_inbox_lists_chats_from_every_wrapped_account_together(): void
    {
        $waha = $this->createProviderAccount('waha', 'waha-wrap', [
            'base_url' => 'https://waha-wrap.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        $fonnte = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('fonnte', 'fonnte-wrap', [
                'endpoint' => 'https://api.fonnte.com/send',
                'token' => 'fonnte-secret',
            ])['id'],
        );

        Http::fake([
            'waha-wrap.test/*' => Http::response([
                [
                    'id' => '6281234567890@c.us',
                    'name' => 'Budi',
                    'lastMessage' => [
                        'id' => 'in-1',
                        'body' => 'Halo Hub',
                        'fromMe' => false,
                        'timestamp' => 1_700_000_000,
                    ],
                ],
            ]),
        ]);

        $this->postJson('/webhooks/whatsapp/'.$fonnte->uuid, [
            'sender' => '628555444333',
            'message' => 'Dari Fonnte',
            'name' => 'Sinta',
        ])->assertOk();

        Livewire::test(WhatsAppInbox::class)
            ->assertSee('Budi')
            ->assertSee('Halo Hub')
            ->assertSee('Sinta')
            ->assertSee('Dari Fonnte')
            ->assertSee('WAHA')
            ->assertSee('Fonnte')
            ->call('selectChat', '628555444333', 'Sinta', $fonnte->id)
            ->assertSee('Dari Fonnte')
            ->assertSee('wa-bubble is-in', false)
            ->assertSet('providerId', $fonnte->id);

        $this->assertNotSame($waha['id'], $fonnte->id);
    }

    public function test_inbound_webhook_appears_in_the_inbox_thread(): void
    {
        $provider = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-webhook-inbox', [
                'base_url' => 'https://waha-hook.test',
                'session' => 'default',
                'api_key' => 'waha-secret',
            ])['id'],
        );

        Http::fake([
            'waha-hook.test/*' => Http::response([]),
        ]);

        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'message',
            'session' => 'default',
            'payload' => [
                'id' => 'true_628999888777@c.us_ABC',
                'from' => '628999888777@c.us',
                'fromMe' => false,
                'body' => 'Pesan masuk dari pelanggan',
                'timestamp' => 1_700_000_200,
                'pushName' => 'Rina',
            ],
        ])->assertOk();

        Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider->id)
            ->assertSee('Rina')
            ->assertSee('Pesan masuk dari pelanggan')
            ->call('selectChat', '628999888777@c.us', 'Rina')
            ->assertSee('Pesan masuk dari pelanggan')
            ->assertSee('wa-bubble is-in', false);
    }

    public function test_gowa_inbox_sends_text_through_the_real_driver_and_keeps_it_as_from_me(): void
    {
        $provider = $this->createProviderAccount('gowa', 'gowa-inbox-send', [
            'base_url' => 'https://gowa-inbox-send.test',
            'username' => 'gateway',
            'password' => 'gowa-secret',
            'device_id' => 'device-main',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/send/message')) {
                return Http::response([
                    'code' => 'SUCCESS',
                    'message' => 'Success',
                    'results' => ['message_id' => 'gowa-remote-1', 'status' => 'sent'],
                ]);
            }

            return Http::response([]);
        });

        $page = Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->call('startNewChat')
            ->set('newRecipient', '081234567890')
            ->set('draft', 'Halo GOWA dari Hub')
            ->call('send')
            ->assertNotified('Pesan terkirim.')
            ->assertSet('chatId', '6281234567890@s.whatsapp.net')
            ->assertSee('Halo GOWA dari Hub')
            ->assertSee('wa-bubble is-out', false);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gowa-inbox-send.test/send/message'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('gateway:gowa-secret'))
            && $request->hasHeader('X-Device-Id', 'device-main')
            && $request['phone'] === '6281234567890@s.whatsapp.net'
            && $request['message'] === 'Halo GOWA dari Hub');

        $stored = InboxMessage::query()->first();
        $this->assertNotNull($stored);
        $this->assertTrue($stored->from_me);
        $this->assertSame('Halo GOWA dari Hub', $stored->body);
        $this->assertTrue(collect($page->get('messages'))->contains(
            fn (array $message): bool => $message['from_me'] === true && $message['body'] === 'Halo GOWA dari Hub',
        ));
    }

    public function test_fonnte_inbox_sends_text_through_the_real_driver_and_keeps_it_as_from_me(): void
    {
        $provider = $this->createProviderAccount('fonnte', 'fonnte-inbox-send', [
            'endpoint' => 'https://fonnte-inbox-send.test/send',
            'token' => 'fonnte-secret',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/send')) {
                return Http::response([
                    'status' => true,
                    'id' => ['fonnte-message-123'],
                    'process' => 'pending',
                    'requestid' => 98765,
                ]);
            }

            return Http::response([]);
        });

        $page = Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->call('startNewChat')
            ->set('newRecipient', '081234567890')
            ->set('draft', 'Halo Fonnte dari Hub')
            ->call('send')
            ->assertNotified('Pesan terkirim.')
            ->assertSet('chatId', '6281234567890')
            ->assertSee('Halo Fonnte dari Hub')
            ->assertSee('wa-bubble is-out', false);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fonnte-inbox-send.test/send'
            && $request->hasHeader('Authorization', 'fonnte-secret')
            && $request->isMultipart()
            && $request->hasFile('target', '6281234567890')
            && $request->hasFile('message', 'Halo Fonnte dari Hub')
            && $request->hasFile('countryCode', '62'));

        $stored = InboxMessage::query()->first();
        $this->assertNotNull($stored);
        $this->assertTrue($stored->from_me);
        $this->assertSame('Halo Fonnte dari Hub', $stored->body);
        $this->assertTrue(collect($page->get('messages'))->contains(
            fn (array $message): bool => $message['from_me'] === true && $message['body'] === 'Halo Fonnte dari Hub',
        ));
    }

    public function test_waba_inbox_sends_text_through_the_real_driver_and_keeps_it_as_from_me(): void
    {
        $provider = $this->createProviderAccount('waba', 'waba-inbox-send', [
            'base_url' => 'https://graph-waba-inbox.test',
            'api_version' => 'v25.0',
            'phone_number_id' => '123456789012345',
            'access_token' => 'meta-system-user-token',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/messages')) {
                return Http::response([
                    'messaging_product' => 'whatsapp',
                    'contacts' => [['wa_id' => '6281234567890']],
                    'messages' => [['id' => 'wamid.123']],
                ]);
            }

            return Http::response([]);
        });

        $page = Livewire::test(WhatsAppInbox::class)
            ->set('providerId', $provider['id'])
            ->call('startNewChat')
            ->set('newRecipient', '081234567890')
            ->set('draft', 'Halo WABA dari Hub')
            ->call('send')
            ->assertNotified('Pesan terkirim.')
            ->assertSet('chatId', '6281234567890')
            ->assertSee('Halo WABA dari Hub')
            ->assertSee('wa-bubble is-out', false);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph-waba-inbox.test/v25.0/123456789012345/messages'
            && $request->hasHeader('Authorization', 'Bearer meta-system-user-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['recipient_type'] === 'individual'
            && $request['to'] === '6281234567890'
            && $request['type'] === 'text'
            && $request['text'] === ['preview_url' => false, 'body' => 'Halo WABA dari Hub']);

        $stored = InboxMessage::query()->first();
        $this->assertNotNull($stored);
        $this->assertTrue($stored->from_me);
        $this->assertSame('Halo WABA dari Hub', $stored->body);
        $this->assertTrue(collect($page->get('messages'))->contains(
            fn (array $message): bool => $message['from_me'] === true && $message['body'] === 'Halo WABA dari Hub',
        ));
    }

    public function test_gowa_inbound_webhook_opens_as_not_from_me_in_the_wrapping_inbox(): void
    {
        $provider = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('gowa', 'gowa-inbox-inbound', [
                'base_url' => 'https://gowa-inbox-inbound.test',
                'username' => 'gateway',
                'password' => 'secret',
            ])['id'],
        );

        Http::fake([
            'gowa-inbox-inbound.test/*' => Http::response([]),
        ]);

        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'sender_id' => '628777666555',
            'chat_id' => '628777666555@s.whatsapp.net',
            'from' => '628777666555@s.whatsapp.net',
            'pushname' => 'Gowa User',
            'timestamp' => '2024-01-15T10:30:00Z',
            'message' => [
                'id' => 'gowa-in-1',
                'text' => 'Ping GOWA',
            ],
        ])->assertOk();

        $page = Livewire::test(WhatsAppInbox::class)
            ->assertSee('Gowa User')
            ->assertSee('Ping GOWA')
            ->call('selectChat', '628777666555@s.whatsapp.net', 'Gowa User', $provider->id)
            ->assertSee('Ping GOWA')
            ->assertSee('wa-bubble is-in', false);

        $stored = InboxMessage::query()->first();
        $this->assertNotNull($stored);
        $this->assertFalse($stored->from_me);
        $this->assertSame('Ping GOWA', $stored->body);
        $this->assertTrue(collect($page->get('messages'))->contains(
            fn (array $message): bool => $message['from_me'] === false && $message['body'] === 'Ping GOWA',
        ));
    }

    public function test_waba_inbound_webhook_opens_as_not_from_me_in_the_wrapping_inbox(): void
    {
        $provider = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waba', 'waba-inbox-inbound', [
                'base_url' => 'https://graph-waba-inbox-in.test',
                'api_version' => 'v25.0',
                'phone_number_id' => '123456789012345',
                'access_token' => 'meta-token',
            ])['id'],
        );

        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'contacts' => [
                                    ['wa_id' => '628321321321', 'profile' => ['name' => 'Meta User']],
                                ],
                                'messages' => [
                                    [
                                        'from' => '628321321321',
                                        'id' => 'wamid.ABC',
                                        'timestamp' => '1700000000',
                                        'type' => 'text',
                                        'text' => ['body' => 'Halo WABA'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $page = Livewire::test(WhatsAppInbox::class)
            ->assertSee('Meta User')
            ->assertSee('Halo WABA')
            ->call('selectChat', '628321321321', 'Meta User', $provider->id)
            ->assertSee('Halo WABA')
            ->assertSee('wa-bubble is-in', false);

        $stored = InboxMessage::query()->first();
        $this->assertNotNull($stored);
        $this->assertFalse($stored->from_me);
        $this->assertSame('Halo WABA', $stored->body);
        $this->assertTrue(collect($page->get('messages'))->contains(
            fn (array $message): bool => $message['from_me'] === false && $message['body'] === 'Halo WABA',
        ));
    }

    public function test_provider_list_has_an_inbox_action(): void
    {
        $provider = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-inbox-link', [
                'base_url' => 'https://waha-link.test',
                'session' => 'default',
                'api_key' => 'key',
            ])['id'],
        );

        Livewire::test(ListProviderAccounts::class)
            ->assertTableActionVisible('inbox', $provider);
    }

    private function assertIdleComposerIsCompact(string $html): void
    {
        $this->assertStringContainsString('wa-inbox-attach-toggle', $html);
        $this->assertStringContainsString('aria-label="Lampiran"', $html);
        $this->assertMatchesRegularExpression(
            '/id="wa-inbox-attachment-panel"[^>]*data-expanded="false"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/id="wa-inbox-attachment-panel"[^>]*\shidden(\s|>)/',
            $html,
        );
    }
}
