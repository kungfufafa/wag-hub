<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\ClientApplications\Pages\EditClientApplication;
use App\Filament\Resources\ClientApplications\RelationManagers\ApiCredentialsRelationManager;
use App\Filament\Resources\GatewayMessages\GatewayMessageResource;
use App\Filament\Resources\GatewayMessages\Pages\ListGatewayMessages;
use App\Filament\Resources\GatewayMessages\Pages\ViewGatewayMessage;
use App\Filament\Resources\ProviderAccounts\Pages\EditProviderAccount;
use App\Filament\Resources\RoutingPolicies\Pages\CreateRoutingPolicy;
use App\Filament\Widgets\GatewayStatsOverview;
use App\Jobs\DispatchGatewayMessage;
use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminResourceBehaviorTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]));
    }

    public function test_message_list_masks_the_recipient_and_does_not_render_the_body(): void
    {
        $message = $this->createMessage('failed', now()->addMinute());

        Livewire::test(ListGatewayMessages::class)
            ->assertCanSeeTableRecords([$message])
            ->assertTableColumnFormattedStateSet(
                'recipient_last4',
                '••••••••7890',
                $message,
            )
            ->assertDontSee('6281234567890')
            ->assertDontSee('Sensitive gateway body');
    }

    public function test_provider_accepted_message_only_shows_its_relevant_lifecycle_result(): void
    {
        $message = $this->createMessage('provider_accepted', now()->addMinute());
        $message->forceFill(['provider_accepted_at' => now()])->save();

        Livewire::test(ViewGatewayMessage::class, ['record' => $message->getKey()])
            ->assertSee('Diterima provider')
            ->assertDontSee('Hasil tidak diketahui');
    }

    public function test_message_detail_shows_the_encrypted_body_for_admin_audit(): void
    {
        $message = $this->createMessage('provider_accepted', now()->addMinute());

        Livewire::test(ViewGatewayMessage::class, ['record' => $message->getKey()])
            ->assertSee('Isi pesan')
            ->assertSee('Sensitive gateway body');
    }

    public function test_message_view_shows_compact_lifecycle_timeline_without_empty_stages(): void
    {
        $message = $this->createMessage('provider_accepted', now()->addMinute());
        $message->forceFill([
            'processing_at' => now()->subSeconds(2),
            'provider_accepted_at' => now()->subSecond(),
            'queued_at' => null,
            'failed_at' => null,
            'outcome_unknown_at' => null,
            'dead_lettered_at' => null,
        ])->save();

        $timeline = GatewayMessageResource::lifecycleTimeline($message->fresh());

        $this->assertSame(
            ['Dibuat', 'Diproses', 'Diterima provider', 'Kedaluwarsa'],
            array_column($timeline, 'label'),
        );

        Livewire::test(ViewGatewayMessage::class, ['record' => $message->getKey()])
            ->assertSee('Siklus hidup')
            ->assertSee('Dibuat')
            ->assertSee('Diproses')
            ->assertSee('Diterima provider')
            ->assertSee('Kedaluwarsa')
            ->assertDontSee('Masuk antrian')
            ->assertDontSee('Dead letter')
            ->assertDontSee('Hasil tidak diketahui');
    }

    public function test_safe_retry_queues_one_job_and_appends_an_admin_event(): void
    {
        Queue::fake();
        $message = $this->createMessage('failed', now()->addMinute());

        Livewire::test(ViewGatewayMessage::class, ['record' => $message->getKey()])
            ->assertActionVisible('retry')
            ->callAction('retry')
            ->assertHasNoActionErrors()
            ->assertNotified('Kirim ulang masuk antrian');

        $this->assertDatabaseHas('gateway_messages', [
            'id' => $message->getKey(),
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->getKey(),
            'type' => 'manual_retry_queued',
            'source' => 'admin',
        ]);
        Queue::assertPushed(
            DispatchGatewayMessage::class,
            fn (DispatchGatewayMessage $job): bool => $job->messageId === $message->getKey(),
        );
    }

    public function test_safe_retry_reports_enqueue_failure_and_leaves_the_message_recoverable(): void
    {
        $message = $this->createMessage('failed', now()->addMinute());
        $message->forceFill(['mode' => 'sync'])->save();
        $dispatchCalls = 0;
        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')
            ->twice()
            ->with(Mockery::type(DispatchGatewayMessage::class))
            ->andReturnUsing(function () use (&$dispatchCalls): string {
                $dispatchCalls++;

                if ($dispatchCalls === 1) {
                    throw new RuntimeException('Queue storage is unavailable.');
                }

                return 'queued-job-id';
            });
        $this->app->instance(BusDispatcher::class, $bus);

        Livewire::test(ViewGatewayMessage::class, ['record' => $message->getKey()])
            ->callAction('retry')
            ->assertHasNoActionErrors()
            ->assertNotified('Kirim ulang disimpan, tetapi antrian tidak tersedia');

        $this->assertSame('queued', $message->fresh()->status);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->getKey(),
            'type' => 'enqueue_failed',
            'source' => 'admin',
        ]);

        $this->artisan('gateway:recover-stale')
            ->expectsOutputToContain('Requeued: 1')
            ->assertSuccessful();

        $this->assertSame(2, $dispatchCalls);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->getKey(),
            'type' => 'enqueue_recovered',
            'source' => 'system',
        ]);
    }

    #[DataProvider('unsafeRetryProvider')]
    public function test_retry_action_is_hidden_for_unsafe_messages(
        string $status,
        bool $expired,
    ): void {
        $message = $this->createMessage(
            $status,
            $expired ? now()->subSecond() : now()->addMinute(),
        );

        Livewire::test(ViewGatewayMessage::class, ['record' => $message->getKey()])
            ->assertActionHidden('retry');
    }

    public function test_credential_issue_action_displays_plaintext_but_persists_only_a_hash(): void
    {
        $application = $this->createClient('credential-action');

        $component = Livewire::test(ApiCredentialsRelationManager::class, [
            'ownerRecord' => $application,
            'pageClass' => EditClientApplication::class,
        ])
            ->assertTableHeaderActionsExistInOrder(['issue'])
            ->callTableAction('issue', data: [
                'name' => 'Shelf production',
                'abilities' => ['messages:send', 'messages:read'],
            ])
            ->assertHasNoActionErrors()
            ->assertActionMounted('showIssuedToken');

        $raw = DB::table('api_credentials')->sole();
        $token = data_get($component->get('mountedActions'), '0.arguments.token');

        $this->assertSame(64, strlen($raw->token_hash));
        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^wgh_[A-Za-z0-9]{64}$/', $token);
        $this->assertStringNotContainsString($token, json_encode($raw, JSON_THROW_ON_ERROR));
        $this->assertSame(hash('sha256', $token), $raw->token_hash);
    }

    public function test_credential_issue_action_rejects_duplicate_names_for_the_same_application(): void
    {
        $application = $this->createClient('credential-duplicate');
        ApiCredential::issue($application, 'Shelf production', ['messages:send']);

        Livewire::test(ApiCredentialsRelationManager::class, [
            'ownerRecord' => $application,
            'pageClass' => EditClientApplication::class,
        ])
            ->callTableAction('issue', data: [
                'name' => 'Shelf production',
                'abilities' => ['messages:send'],
            ])
            ->assertHasActionErrors(['name']);

        $this->assertSame(1, ApiCredential::query()->where('client_application_id', $application->id)->count());
    }

    public function test_blank_provider_secret_on_edit_preserves_the_existing_encrypted_secret(): void
    {

        $provider = ProviderAccount::forceCreate([
            'name' => 'WAHA Primary',
            'slug' => 'waha-primary',
            'driver' => 'waha',
            'configuration' => [
                'base_url' => 'https://waha.internal.example',
                'session' => 'primary',
                'api_key' => 'existing-provider-secret',
            ],
            'is_active' => true,
            'health_status' => 'healthy',
            'timeout_seconds' => 15,
        ]);

        Livewire::test(EditProviderAccount::class, ['record' => $provider->getKey()])
            ->assertSchemaStateSet([
                'configuration.base_url' => 'https://waha.internal.example',
                'configuration.session' => 'primary',
            ])
            ->assertSchemaStateSet(['configuration.api_key' => null])
            ->assertDontSee('existing-provider-secret')
            ->fillForm([
                'name' => 'WAHA Primary',
                'slug' => 'waha-primary',
                'driver' => 'waha',
                'configuration' => [
                    'base_url' => 'https://waha-new.internal.example',
                    'session' => 'primary',
                    'api_key' => '',
                ],
                'is_active' => true,
                'timeout_seconds' => 15,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $configuration = $provider->fresh()->configuration;

        $this->assertSame('https://waha-new.internal.example', $configuration['base_url']);
        $this->assertSame('existing-provider-secret', $configuration['api_key']);
        $this->assertStringNotContainsString(
            'existing-provider-secret',
            DB::table('provider_accounts')->where('id', $provider->id)->value('configuration'),
        );
    }

    public function test_edit_form_displays_a_fonnte_endpoint_but_not_its_token(): void
    {
        $provider = ProviderAccount::forceCreate([
            'name' => 'Fonnte Primary',
            'slug' => 'fonnte-primary',
            'driver' => 'fonnte',
            'configuration' => [
                'endpoint' => 'https://api.fonnte.com/send',
                'token' => 'existing-fonnte-token',
            ],
            'is_active' => true,
            'health_status' => 'healthy',
            'timeout_seconds' => 15,
        ]);

        Livewire::test(EditProviderAccount::class, ['record' => $provider->getKey()])
            ->assertSchemaStateSet(['configuration.endpoint' => 'https://api.fonnte.com/send'])
            ->assertSchemaStateSet(['configuration.token' => null])
            ->assertDontSee('existing-fonnte-token');
    }

    public function test_routing_policy_rejects_the_same_provider_twice(): void
    {
        $application = $this->createClient('route-application');
        $provider = ProviderAccount::forceCreate([
            'name' => 'Fonnte Primary',
            'slug' => 'fonnte-primary',
            'driver' => 'fonnte',
            'configuration' => [
                'endpoint' => 'https://api.fonnte.com/send',
                'token' => 'provider-token',
            ],
            'is_active' => true,
            'health_status' => 'healthy',
            'timeout_seconds' => 15,
        ]);

        Livewire::test(CreateRoutingPolicy::class)
            ->fillForm([
                'client_application_id' => $application->getKey(),
                'name' => 'Default notifications',
                'key' => 'default',
                'purpose' => 'notification',
                'is_default' => true,
                'is_active' => true,
                'steps' => [
                    ['provider_account_id' => $provider->getKey(), 'is_active' => true],
                    ['provider_account_id' => $provider->getKey(), 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseCount('routing_policies', 0);
        $this->assertDatabaseCount('routing_steps', 0);
    }

    public function test_dashboard_stats_summarize_the_delivery_ledger(): void
    {
        $this->createMessage('queued', now()->addMinute());
        $this->createMessage('provider_accepted', now()->addMinute());
        $fallbackMessage = $this->createMessage('failed', now()->addMinute());
        $fallbackMessage->events()->create([
            'type' => 'fallback_started',
            'source' => 'worker',
            'occurred_at' => now(),
        ]);

        Livewire::test(GatewayStatsOverview::class)
            ->assertSee('Antrian')
            ->assertSee('Diterima provider')
            ->assertSee('Gagal')
            ->assertSee('Rasio cadangan')
            ->assertSee('33.3%');
    }

    public static function unsafeRetryProvider(): array
    {
        return [
            'queued' => ['queued', false],
            'processing' => ['processing', false],
            'accepted' => ['provider_accepted', false],
            'unknown outcome' => ['outcome_unknown', false],
            'expired failure' => ['failed', true],
        ];
    }

    private function createClient(string $slug): ClientApplication
    {
        return ClientApplication::forceCreate([
            'name' => Str::headline($slug),
            'slug' => $slug,
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);
    }

    private function createMessage(string $status, mixed $expiresAt): GatewayMessage
    {
        $application = $this->createClient('message-'.Str::lower(Str::random(8)));
        $recipient = '6281234567890';
        $body = 'Sensitive gateway body';

        return GatewayMessage::forceCreate([
            'client_application_id' => $application->getKey(),
            'idempotency_key' => 'admin:'.Str::uuid(),
            'payload_hash' => hash('sha256', $recipient.'|'.$body),
            'correlation_id' => (string) Str::uuid(),
            'recipient' => $recipient,
            'recipient_hash' => hash_hmac('sha256', $recipient, 'admin-test'),
            'recipient_last4' => '7890',
            'body' => $body,
            'purpose' => 'notification',
            'route_key' => 'default',
            'mode' => 'async',
            'priority' => 10,
            'status' => $status,
            'expires_at' => $expiresAt,
            'failed_at' => $status === 'failed' ? now() : null,
        ]);
    }
}
