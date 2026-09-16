<?php

namespace Tests\Feature\Automation;

use App\Models\BotConversationState;
use App\Models\BotFlow;
use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class BotFlowTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['id' => 'srv_ack'], 201)]);
    }

    public function test_keyword_starts_the_menu_and_a_choice_is_answered(): void
    {
        $provider = $this->wahaProvider();
        $this->menuFlow($provider);

        // Customer opens the menu.
        $this->inbound($provider, 'm1', 'menu');
        $replies = $this->botReplies($provider);
        $this->assertCount(1, $replies);
        $this->assertStringContainsString('Jam operasional', $replies->last()->plaintextBody());
        $this->assertDatabaseHas('bot_conversation_states', [
            'provider_account_id' => $provider->id,
            'chat_key' => '628111000111',
        ]);

        // Customer picks option 1.
        $this->inbound($provider, 'm2', '1');
        $replies = $this->botReplies($provider);
        $this->assertCount(2, $replies);
        $this->assertStringContainsString('08.00', $replies->last()->plaintextBody());
    }

    public function test_unknown_choice_gets_fallback_and_menu(): void
    {
        $provider = $this->wahaProvider();
        $this->menuFlow($provider);

        $this->inbound($provider, 'u1', 'menu');
        $this->inbound($provider, 'u2', 'apa kabar');

        $last = $this->botReplies($provider)->last()->plaintextBody();
        $this->assertStringContainsString('tidak dikenali', $last);
        $this->assertStringContainsString('Jam operasional', $last); // menu re-shown
    }

    public function test_handoff_option_ends_the_flow(): void
    {
        $provider = $this->wahaProvider();
        $this->menuFlow($provider);

        $this->inbound($provider, 'h1', 'menu');
        $this->inbound($provider, 'h2', '3'); // "Bicara ke agen" (handoff)

        $this->assertStringContainsString('agen', mb_strtolower($this->botReplies($provider)->last()->plaintextBody()));
        $this->assertDatabaseMissing('bot_conversation_states', [
            'provider_account_id' => $provider->id,
            'chat_key' => '628111000111',
        ]);
    }

    private function menuFlow(ProviderAccount $provider): BotFlow
    {
        return BotFlow::create([
            'provider_account_id' => $provider->id,
            'name' => 'Menu utama',
            'is_active' => true,
            'priority' => 0,
            'trigger_type' => 'keyword',
            'keywords' => ['menu', 'halo'],
            'header' => "Selamat datang! Silakan pilih:",
            'options' => [
                ['key' => '1', 'label' => 'Jam operasional', 'action' => 'reply', 'reply' => 'Kami buka Senin-Jumat 08.00-17.00.'],
                ['key' => '2', 'label' => 'Alamat toko', 'action' => 'reply', 'reply' => 'Jl. Merdeka No. 1.'],
                ['key' => '3', 'label' => 'Bicara ke agen', 'action' => 'handoff', 'reply' => 'Menghubungkan ke agen kami...'],
            ],
            'footer' => 'Ketik angka pilihan Anda.',
            'fallback_reply' => 'Maaf, pilihan tidak dikenali.',
            'session_ttl_minutes' => 10,
        ]);
    }

    private function inbound(ProviderAccount $provider, string $id, string $body): void
    {
        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'message',
            'payload' => [
                'id' => $id,
                'from' => '628111000111@c.us',
                'fromMe' => false,
                'body' => $body,
                'timestamp' => 1_700_000_000,
                'pushName' => 'Pelanggan',
            ],
        ])->assertOk();
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
            ->orderBy('id')
            ->get();
    }

    private function wahaProvider(): ProviderAccount
    {
        return ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-flow', [
                'base_url' => 'https://waha-flow.test',
                'session' => 'default',
            ])['id'],
        );
    }
}
