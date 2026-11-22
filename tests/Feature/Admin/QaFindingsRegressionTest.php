<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\AutoReplyRules\Pages\ListAutoReplyRules;
use App\Filament\Resources\BotFlows\Pages\CreateBotFlow;
use App\Filament\Resources\KnowledgeBaseEntries\Pages\ListKnowledgeBaseEntries;
use App\Filament\Resources\ProviderAccounts\Pages\CreateProviderAccount;
use App\Filament\Support\ConnectionLabels;
use App\Models\AutoReplyRule;
use App\Models\BotFlow;
use App\Models\KnowledgeBaseEntry;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class QaFindingsRegressionTest extends TestCase
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

    public function test_waha_form_does_not_inherit_the_waba_graph_url(): void
    {
        Livewire::test(CreateProviderAccount::class)
            ->fillForm(['driver' => 'waha'])
            ->assertSchemaStateSet(['configuration.base_url' => null]);
    }

    public function test_waba_account_saved_without_graph_url_uses_the_default(): void
    {
        Livewire::test(CreateProviderAccount::class)
            ->fillForm([
                'name' => 'QA WABA',
                'slug' => 'qa-waba',
                'driver' => 'waba',
                'configuration.api_version' => 'v21.0',
                'configuration.phone_number_id' => '123456',
                'configuration.access_token' => 'secret-token',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'https://graph.facebook.com',
            ProviderAccount::query()->where('slug', 'qa-waba')->sole()->configuration['base_url'],
        );
    }

    public function test_auto_reply_list_shows_saved_keywords(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-qa', ['base_url' => 'https://waha.test', 'session' => 'default']);
        $rule = AutoReplyRule::create([
            'provider_account_id' => $provider['id'],
            'name' => 'QA Reply',
            'is_active' => true,
            'priority' => 0,
            'match_type' => 'keyword',
            'match_mode' => 'exact',
            'keywords' => ['haloqa', 'pagi'],
            'reply_body' => 'Halo!',
        ]);

        Livewire::test(ListAutoReplyRules::class)
            ->assertCanSeeTableRecords([$rule])
            ->assertSee('haloqa, pagi');
    }

    public function test_bot_menu_rejects_duplicate_option_keys(): void
    {
        $provider = $this->createProviderAccount('waha', 'waha-bot', ['base_url' => 'https://waha.test', 'session' => 'default']);

        Livewire::test(CreateBotFlow::class)
            ->fillForm([
                'provider_account_id' => $provider['id'],
                'name' => 'QA Menu',
                'trigger_type' => 'keyword',
                'keywords' => ['menu'],
                'header' => 'Pilih:',
                'options' => [
                    ['key' => '1', 'label' => 'Satu', 'action' => 'reply', 'reply' => 'A'],
                    ['key' => '1', 'label' => 'Dua', 'action' => 'reply', 'reply' => 'B'],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['options.0.key', 'options.1.key']);

        $this->assertSame(0, BotFlow::query()->count());
    }

    public function test_knowledge_base_active_column_is_translated(): void
    {
        app()->setLocale('id');
        $provider = $this->createProviderAccount('waha', 'waha-kb', ['base_url' => 'https://waha.test', 'session' => 'default']);
        KnowledgeBaseEntry::create([
            'provider_account_id' => $provider['id'],
            'title' => 'Jam buka',
            'content' => 'Senin-Jumat',
            'is_active' => true,
        ]);

        Livewire::test(ListKnowledgeBaseEntries::class)
            ->assertSee('Ya')
            ->assertDontSee('>Yes<', false);
    }

    public function test_next_step_never_says_ready_for_a_connection_that_is_not_ready(): void
    {
        $connecting = new WhatsAppConnection(['type' => 'provider_route', 'status' => 'connecting', 'recommended_action' => null]);
        $ready = new WhatsAppConnection(['type' => 'provider_route', 'status' => 'ready', 'recommended_action' => null]);

        $this->assertNotSame('Siap dipakai', ConnectionLabels::nextStep($connecting));
        $this->assertSame('Siap dipakai', ConnectionLabels::nextStep($ready));
    }
}
