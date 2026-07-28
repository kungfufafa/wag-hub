<?php

namespace Tests\Feature\Alerts;

use App\Domain\Delivery\ProviderResult;
use App\Events\ProviderHealthChanged;
use App\Models\ProviderAccount;
use App\Services\ProviderHealthRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ProviderHealthChangedEventTest extends TestCase
{
    use BuildsGatewayFixtures;
    use RefreshDatabase;

    public function test_recorder_dispatches_event_only_when_status_changes(): void
    {
        Event::fake([ProviderHealthChanged::class]);
        config()->set('gateway.provider_health.failure_threshold', 3);

        $provider = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-alert-event', [
                'base_url' => 'https://waha-alert-event.test',
                'api_key' => 'secret',
                'session' => 'default',
            ])['id'],
        );

        $recorder = app(ProviderHealthRecorder::class);

        $recorder->record($provider, ProviderResult::providerFailed(errorMessage: 'boom-1'));
        Event::assertDispatched(ProviderHealthChanged::class, function (ProviderHealthChanged $e) {
            return $e->fromStatus === 'healthy'
                && $e->toStatus === 'degraded'
                && $e->errorSummary !== null
                && str_contains($e->errorSummary, 'boom-1');
        });

        Event::fake([ProviderHealthChanged::class]);
        $provider = $provider->fresh();
        $recorder->record($provider, ProviderResult::providerFailed(errorMessage: 'boom-2'));
        Event::assertNotDispatched(ProviderHealthChanged::class);

        Event::fake([ProviderHealthChanged::class]);
        $provider = $provider->fresh();
        $recorder->record($provider, ProviderResult::providerFailed(errorMessage: 'boom-3'));
        Event::assertDispatched(ProviderHealthChanged::class, fn (ProviderHealthChanged $e) => $e->toStatus === 'unavailable');

        Event::fake([ProviderHealthChanged::class]);
        $provider = $provider->fresh();
        $recorder->record($provider, ProviderResult::accepted());
        Event::assertDispatched(ProviderHealthChanged::class, fn (ProviderHealthChanged $e) => $e->fromStatus === 'unavailable' && $e->toStatus === 'healthy');
    }

    public function test_message_rejection_degrades_health_and_dispatches_alert_event(): void
    {
        Event::fake([ProviderHealthChanged::class]);
        config()->set('gateway.provider_health.failure_threshold', 3);

        $provider = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-reject-alert-event', [
                'base_url' => 'https://waha-reject-alert-event.test',
                'api_key' => 'secret',
                'session' => 'default',
            ])['id'],
        );

        app(ProviderHealthRecorder::class)->record(
            $provider,
            ProviderResult::rejected(
                httpStatus: 422,
                errorCode: 'invalid_message',
                errorMessage: 'Invalid chatId',
            ),
        );

        Event::assertDispatched(ProviderHealthChanged::class, function (ProviderHealthChanged $e) {
            return $e->fromStatus === 'healthy'
                && $e->toStatus === 'degraded'
                && $e->consecutiveFailures === 1
                && $e->errorSummary !== null
                && str_contains($e->errorSummary, 'Invalid chatId');
        });

        $fresh = $provider->fresh();
        $this->assertSame('degraded', $fresh->health_status);
        $this->assertSame(1, $fresh->consecutive_failures);
    }
}
