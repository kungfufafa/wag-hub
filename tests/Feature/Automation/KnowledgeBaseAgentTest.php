<?php

namespace Tests\Feature\Automation;

use App\Models\GatewayMessage;
use App\Models\KnowledgeBaseEntry;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class KnowledgeBaseAgentTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_free_text_question_is_answered_from_the_knowledge_base(): void
    {
        Http::fake(['*' => Http::response(['id' => 'srv_ack'], 201)]);
        $provider = $this->wahaProvider();
        $this->entry($provider, 'Jam operasional', 'Kami buka Senin sampai Jumat pukul 08.00-17.00 WIB.', ['jam', 'buka', 'operasional']);
        $this->entry($provider, 'Ongkos kirim', 'Ongkir mulai Rp10.000 tergantung wilayah.', ['ongkir', 'kirim', 'ongkos']);

        $this->inbound($provider, 'kb1', 'kak toko buka jam berapa ya?');

        $replies = $this->botReplies($provider);
        $this->assertCount(1, $replies);
        $this->assertStringContainsString('08.00-17.00', $replies->first()->plaintextBody());
    }

    public function test_irrelevant_message_gets_no_answer(): void
    {
        Http::fake(['*' => Http::response(['id' => 'srv_ack'], 201)]);
        $provider = $this->wahaProvider();
        $this->entry($provider, 'Jam operasional', 'Kami buka 08.00-17.00.', ['jam', 'buka']);

        $this->inbound($provider, 'kb2', 'zxcvbnm qwerty');

        $this->assertCount(0, $this->botReplies($provider));
    }

    public function test_llm_layer_phrases_the_answer_when_configured(): void
    {
        config()->set('gateway.ai.llm.enabled', true);
        config()->set('gateway.ai.llm.api_key', 'sk-test');
        config()->set('gateway.ai.llm.endpoint', 'https://api.openai.test/v1/chat/completions');

        Http::fake([
            'api.openai.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'Halo kak! Toko kami buka 08.00–17.00 WIB ya 😊']]],
            ], 200),
            '*' => Http::response(['id' => 'srv_ack'], 201),
        ]);

        $provider = $this->wahaProvider();
        $this->entry($provider, 'Jam operasional', 'Kami buka 08.00-17.00 WIB.', ['jam', 'buka']);

        $this->inbound($provider, 'kb3', 'jam buka toko?');

        $replies = $this->botReplies($provider);
        $this->assertCount(1, $replies);
        $this->assertStringContainsString('😊', $replies->first()->plaintextBody());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.openai.test'));
    }

    private function entry(ProviderAccount $provider, string $title, string $content, array $keywords = []): KnowledgeBaseEntry
    {
        return KnowledgeBaseEntry::create([
            'provider_account_id' => $provider->id,
            'title' => $title,
            'content' => $content,
            'keywords' => $keywords === [] ? null : $keywords,
            'is_active' => true,
        ]);
    }

    private function inbound(ProviderAccount $provider, string $id, string $body): void
    {
        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'message',
            'payload' => [
                'id' => $id,
                'from' => '628111000222@c.us',
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
            $this->createProviderAccount('waha', 'waha-kb', [
                'base_url' => 'https://waha-kb.test',
                'session' => 'default',
            ])['id'],
        );
    }
}
