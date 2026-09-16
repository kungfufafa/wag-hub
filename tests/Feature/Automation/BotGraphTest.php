<?php

namespace Tests\Feature\Automation;

use App\Models\BotGraph;
use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class BotGraphTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['id' => 'srv_ack'], 201)]);
    }

    public function test_condition_true_branch_sends_message(): void
    {
        $provider = $this->wahaProvider();
        $this->graph($provider);

        $this->inbound($provider, 'g1', 'halo mau beli baju');

        $this->assertStringContainsString('nama produk', $this->botReplies($provider)->last()->plaintextBody());
    }

    public function test_condition_false_branch_opens_menu_then_choice_and_handoff(): void
    {
        $provider = $this->wahaProvider();
        $this->graph($provider);

        // Trigger + condition false -> menu.
        $this->inbound($provider, 'g2', 'halo');
        $this->assertStringContainsString('Jam operasional', $this->botReplies($provider)->last()->plaintextBody());
        $this->assertDatabaseHas('bot_conversation_states', [
            'provider_account_id' => $provider->id,
            'chat_key' => '628111000333',
            'node_id' => '4',
        ]);

        // Choose option 1 (reply).
        $this->inbound($provider, 'g3', '1');
        $this->assertStringContainsString('08.00', $this->botReplies($provider)->last()->plaintextBody());

        // Choose option 2 (handoff) ends the graph.
        $this->inbound($provider, 'g4', '2');
        $this->assertStringContainsString('agen', mb_strtolower($this->botReplies($provider)->last()->plaintextBody()));
        $this->assertDatabaseMissing('bot_conversation_states', [
            'provider_account_id' => $provider->id,
            'chat_key' => '628111000333',
        ]);
    }

    private function graph(ProviderAccount $provider): BotGraph
    {
        $conn = fn (string $node): array => ['connections' => [['node' => $node, 'output' => 'input_1']]];

        return BotGraph::create([
            'provider_account_id' => $provider->id,
            'name' => 'Graph CS',
            'is_active' => true,
            'priority' => 0,
            'definition' => ['drawflow' => ['Home' => ['data' => [
                '1' => ['id' => 1, 'name' => 'trigger', 'data' => ['trigger_type' => 'keyword', 'keywords' => 'halo,mulai'],
                    'outputs' => ['output_1' => $conn('2')]],
                '2' => ['id' => 2, 'name' => 'condition', 'data' => ['keywords' => 'beli,pesan,order'],
                    'outputs' => ['output_1' => $conn('3'), 'output_2' => $conn('4')]],
                '3' => ['id' => 3, 'name' => 'message', 'data' => ['text' => 'Silakan sebutkan nama produk yang ingin dibeli.'],
                    'outputs' => []],
                '4' => ['id' => 4, 'name' => 'menu', 'data' => [
                    'header' => 'Silakan pilih:',
                    'options' => "1|Jam operasional|reply|Kami buka Senin-Jumat 08.00-17.00.\n2|Bicara ke agen|handoff|Menghubungkan ke agen kami...",
                    'footer' => 'Ketik angka pilihan.',
                ], 'outputs' => []],
            ]]]],
        ]);
    }

    private function inbound(ProviderAccount $provider, string $id, string $body): void
    {
        $this->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'message',
            'payload' => [
                'id' => $id,
                'from' => '628111000333@c.us',
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
            $this->createProviderAccount('waha', 'waha-graph', [
                'base_url' => 'https://waha-graph.test',
                'session' => 'default',
            ])['id'],
        );
    }
}
