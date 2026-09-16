<?php

namespace Tests\Feature\Automation;

use App\Models\AutoReplyRule;
use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\WhatsAppInbox;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class AutoReplyTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        // The reply dispatches synchronously (queue=sync); fake the WAHA send.
        Http::fake(['*' => Http::response(['id' => 'srv_ack_1'], 201)]);
    }

    public function test_keyword_message_triggers_an_auto_reply(): void
    {
        $provider = $this->wahaProvider();
        $this->rule($provider, 'keyword', reply: 'Menu kami: 1. Produk 2. Jam buka', keywords: ['menu']);

        $this->sendInbound($provider, 'in-1', 'Halo, boleh lihat menu?');

        $replies = $this->botReplies($provider);
        $this->assertCount(1, $replies);
        $this->assertSame('Menu kami: 1. Produk 2. Jam buka', $replies->first()->plaintextBody());
    }

    public function test_auto_reply_is_idempotent_across_redelivered_webhooks(): void
    {
        $provider = $this->wahaProvider();
        $this->rule($provider, 'keyword', reply: 'Halo!', keywords: ['halo']);

        $this->sendInbound($provider, 'dup-1', 'halo');
        $this->sendInbound($provider, 'dup-1', 'halo');

        $this->assertCount(1, $this->botReplies($provider));
    }

    public function test_fallback_replies_when_no_keyword_matches(): void
    {
        $provider = $this->wahaProvider();
        $this->rule($provider, 'keyword', reply: 'Menu', keywords: ['menu'], priority: 0);
        $this->rule($provider, 'fallback', reply: 'Maaf, ketik "menu" untuk pilihan.', priority: 99);

        $this->sendInbound($provider, 'fb-1', 'pesanan saya mana');

        $replies = $this->botReplies($provider);
        $this->assertCount(1, $replies);
        $this->assertSame('Maaf, ketik "menu" untuk pilihan.', $replies->first()->plaintextBody());
    }

    public function test_no_reply_when_nothing_matches(): void
    {
        $provider = $this->wahaProvider();
        $this->rule($provider, 'keyword', reply: 'Menu', keywords: ['menu']);

        $this->sendInbound($provider, 'nm-1', 'terima kasih');

        $this->assertCount(0, $this->botReplies($provider));
    }

    public function test_bot_pauses_after_a_human_agent_replies(): void
    {
        $provider = $this->wahaProvider();
        $this->rule($provider, 'keyword', reply: 'Balasan bot', keywords: ['menu']);

        // A human agent takes over the chat from the Inbox.
        $this->actingAs(User::factory()->create());
        app(WhatsAppInbox::class)->send($provider, '6289900000002', 'Halo, dibantu ya kak.');

        // Customer then sends a keyword — bot must stay silent (handoff).
        $this->sendInbound($provider, 'hp-1', 'menu', from: '6289900000002@c.us');

        $this->assertCount(0, $this->botReplies($provider));
    }

    private function sendInbound(ProviderAccount $provider, string $id, string $body, string $from = '628111000111@c.us'): void
    {
        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'message',
            'payload' => [
                'id' => $id,
                'from' => $from,
                'fromMe' => false,
                'body' => $body,
                'timestamp' => 1_700_000_000,
                'pushName' => 'Pelanggan',
            ],
        ])->assertOk();
    }

    /**
     * @param  list<string>  $keywords
     */
    private function rule(
        ProviderAccount $provider,
        string $matchType,
        string $reply,
        array $keywords = [],
        int $priority = 0,
    ): AutoReplyRule {
        return AutoReplyRule::create([
            'provider_account_id' => $provider->id,
            'name' => ucfirst($matchType).' '.$priority,
            'is_active' => true,
            'priority' => $priority,
            'match_type' => $matchType,
            'match_mode' => $matchType === 'keyword' ? 'contains' : null,
            'keywords' => $keywords === [] ? null : $keywords,
            'reply_body' => $reply,
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, GatewayMessage>
     */
    private function botReplies(ProviderAccount $provider)
    {
        return GatewayMessage::query()
            ->where('origin', 'inbox')
            ->where('pinned_provider_account_id', $provider->id)
            ->whereNull('origin_user_id')
            ->get();
    }

    private function wahaProvider(): ProviderAccount
    {
        return ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-bot', [
                'base_url' => 'https://waha-bot.test',
                'session' => 'default',
            ])['id'],
        );
    }
}
