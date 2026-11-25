<?php

namespace Tests\Feature\Webhooks;

use App\Models\GatewayMessage;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_waha_webhook_stores_an_inbound_message(): void
    {
        $provider = $this->provider('waha', 'waha-hook', [
            'base_url' => 'https://waha-hook.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
            'webhook_secret' => 'hook-token',
        ]);

        $this->withHeader('X-Webhook-Token', 'hook-token')->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'message',
            'payload' => [
                'id' => 'in-waha-1',
                'from' => '628111222333@c.us',
                'fromMe' => false,
                'body' => 'Halo inbound',
                'timestamp' => 1_700_000_000,
                'pushName' => 'Andi',
            ],
        ])->assertOk()->assertJson(['ok' => true, 'recorded' => 1]);

        $conversation = InboxConversation::query()->first();
        $message = InboxMessage::query()->first();

        $this->assertNotNull($conversation);
        $this->assertSame('Andi', $conversation->title);
        $this->assertFalse($message->from_me);
        $this->assertSame('Halo inbound', $message->body);
    }

    public function test_fonnte_webhook_stores_an_inbound_message(): void
    {
        $provider = $this->provider('fonnte', 'fonnte-hook', [
            'endpoint' => 'https://api.fonnte.com/send',
            'token' => 'fonnte-secret',
            'webhook_secret' => 'hook-token',
        ]);

        $this->withHeader('X-Webhook-Token', 'hook-token')->post('/webhooks/whatsapp/'.$provider->uuid, [
            'sender' => '628555444333',
            'message' => 'Dari Fonnte',
            'name' => 'Sinta',
        ])->assertOk();

        $this->assertSame('Sinta', InboxConversation::query()->value('title'));
        $this->assertSame('Dari Fonnte', InboxMessage::query()->first()?->body);
        $this->assertFalse((bool) InboxMessage::query()->first()?->from_me);
    }

    public function test_gowa_webhook_stores_an_inbound_message(): void
    {
        $provider = $this->provider('gowa', 'gowa-hook', [
            'base_url' => 'https://gowa-hook.test',
            'username' => 'gateway',
            'password' => 'secret',
            'webhook_secret' => 'hook-token',
        ]);

        $this->withHeader('X-Webhook-Token', 'hook-token')->postJson('/webhooks/whatsapp/'.$provider->uuid, [
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

        $this->assertSame('Ping GOWA', InboxMessage::query()->first()?->body);
        $this->assertFalse((bool) InboxMessage::query()->first()?->from_me);
    }

    public function test_waba_subscribe_challenge_and_inbound_message(): void
    {
        $provider = $this->provider('waba', 'waba-hook', [
            'base_url' => 'https://graph.facebook.com',
            'api_version' => 'v25.0',
            'phone_number_id' => '1234567890',
            'access_token' => 'meta-token',
            'app_secret' => 'meta-app-secret',
            'webhook_secret' => 'verify-me',
        ]);

        $this->get('/webhooks/whatsapp/'.$provider->uuid.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'verify-me',
            'hub.challenge' => 'challenge-token',
        ]))->assertOk()->assertSee('challenge-token');

        $this->signedWabaPost($provider, 'meta-app-secret', [
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

        $this->assertSame('Halo WABA', InboxMessage::query()->first()?->body);
        $this->assertSame('Meta User', InboxConversation::query()->value('title'));
    }

    public function test_inactive_provider_still_stores_inbound_webhooks(): void
    {
        $provider = $this->provider('waha', 'waha-inactive-hook', [
            'base_url' => 'https://waha-inactive.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
            'webhook_secret' => 'hook-token',
        ]);
        $provider->update(['is_active' => false]);

        $this->withHeader('X-Webhook-Token', 'hook-token')->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'message',
            'payload' => [
                'id' => 'in-inactive',
                'from' => '628222333444@c.us',
                'fromMe' => false,
                'body' => 'Tetap masuk',
                'pushName' => 'Dina',
            ],
        ])->assertOk();

        $this->assertSame('Tetap masuk', InboxMessage::query()->first()?->body);
    }

    public function test_webhook_secret_rejects_unauthenticated_posts(): void
    {
        $provider = $this->provider('waha', 'waha-secret-hook', [
            'base_url' => 'https://waha-secret.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
            'webhook_secret' => 'inbox-token',
        ]);

        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'message',
            'payload' => [
                'id' => 'nope',
                'from' => '628111111111@c.us',
                'fromMe' => false,
                'body' => 'should not store',
            ],
        ])->assertUnauthorized();

        $this->postJson('/webhooks/whatsapp/'.$provider->uuid.'?token=inbox-token', [
            'event' => 'message',
            'payload' => [
                'id' => 'ok-1',
                'from' => '628111111111@c.us',
                'fromMe' => false,
                'body' => 'authorized inbound',
            ],
        ])->assertOk();

        $this->assertSame(1, InboxMessage::query()->count());
    }

    public function test_waba_post_without_a_valid_signature_is_rejected(): void
    {
        $provider = $this->provider('waba', 'waba-unsigned', [
            'base_url' => 'https://graph.facebook.com',
            'api_version' => 'v25.0',
            'phone_number_id' => '1234567890',
            'access_token' => 'meta-token',
            'app_secret' => 'meta-app-secret',
            'webhook_secret' => 'verify-me',
        ]);
        $payload = $this->wabaPayload('wamid.FORGED');

        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, $payload)->assertUnauthorized();
        $this->withHeader('X-Hub-Signature-256', 'sha256='.hash_hmac('sha256', (string) json_encode($payload), 'wrong-secret'))
            ->postJson('/webhooks/whatsapp/'.$provider->uuid, $payload)
            ->assertUnauthorized();
        $this->withHeader('X-Webhook-Token', 'verify-me')
            ->postJson('/webhooks/whatsapp/'.$provider->uuid.'?token=verify-me', $payload)
            ->assertUnauthorized();

        $this->assertSame(0, InboxConversation::query()->count());
        $this->assertSame(0, InboxMessage::query()->count());
        $this->assertSame(0, GatewayMessage::query()->count());

        $this->signedWabaPost($provider, 'meta-app-secret', $payload)->assertOk()->assertJson(['recorded' => 1]);
    }

    public function test_waba_without_app_secret_rejects_even_signed_posts(): void
    {
        $provider = $this->provider('waba', 'waba-no-app-secret', [
            'base_url' => 'https://graph.facebook.com',
            'api_version' => 'v25.0',
            'phone_number_id' => '1234567890',
            'access_token' => 'meta-token',
            'webhook_secret' => 'verify-me',
        ]);

        $this->signedWabaPost($provider, '', $this->wabaPayload('wamid.EMPTY'))->assertUnauthorized();

        $this->assertSame(0, InboxMessage::query()->count());
    }

    public function test_account_without_webhook_secret_rejects_posts_and_verification(): void
    {
        $provider = $this->provider('waha', 'waha-no-secret', [
            'base_url' => 'https://waha-no-secret.test',
            'session' => 'default',
            'api_key' => 'waha-secret',
        ]);
        $provider->forceFill(['session_status' => 'working'])->save();

        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'message',
            'payload' => ['id' => 'nope', 'from' => '628111111111@c.us', 'fromMe' => false, 'body' => 'forged'],
        ])->assertUnauthorized();

        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'session.status',
            'payload' => ['name' => 'default', 'status' => 'FAILED'],
        ])->assertUnauthorized();

        $this->get('/webhooks/whatsapp/'.$provider->uuid.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'anything',
            'hub.challenge' => 'challenge-token',
        ]))->assertForbidden()->assertDontSee('challenge-token');

        $this->assertSame(0, InboxMessage::query()->count());
        $this->assertSame('working', $provider->fresh()->session_status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedWabaPost(ProviderAccount $provider, string $appSecret, array $payload): TestResponse
    {
        return $this->withHeader('X-Hub-Signature-256', 'sha256='.hash_hmac('sha256', (string) json_encode($payload), $appSecret))
            ->postJson('/webhooks/whatsapp/'.$provider->uuid, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function wabaPayload(string $id): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => '628999888777', 'profile' => ['name' => 'Forged']]],
                        'messages' => [[
                            'from' => '628999888777',
                            'id' => $id,
                            'timestamp' => '1700000000',
                            'type' => 'text',
                            'text' => ['body' => 'Halo'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function provider(string $driver, string $slug, array $configuration): ProviderAccount
    {
        return ProviderAccount::query()->findOrFail(
            $this->createProviderAccount($driver, $slug, $configuration)['id'],
        );
    }
}
