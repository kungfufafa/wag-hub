<?php

namespace App\Services;

use App\Domain\Delivery\ProviderOutcome;
use App\Domain\Delivery\ProviderResult;
use App\Domain\NumberCheck\NumberCheckResult;
use App\Events\ProviderHealthChanged;
use App\Models\ProviderAccount;
use App\Services\Alerts\AlertErrorSummarizer;
use Illuminate\Support\Facades\DB;

final readonly class ProviderHealthRecorder
{
    public function record(ProviderAccount $provider, ProviderResult $result): void
    {
        if (! in_array($result->outcome, [
            ProviderOutcome::Accepted,
            ProviderOutcome::ProviderFailed,
            ProviderOutcome::OutcomeUnknown,
        ], true)) {
            return;
        }

        DB::transaction(function () use ($provider, $result): void {
            $lockedProvider = ProviderAccount::query()
                ->lockForUpdate()
                ->find($provider->getKey());

            if ($lockedProvider === null) {
                return;
            }

            $previousStatus = (string) $lockedProvider->health_status;

            if ($result->outcome === ProviderOutcome::Accepted) {
                $lockedProvider->forceFill([
                    'consecutive_failures' => 0,
                    'health_status' => 'healthy',
                    'circuit_open_until' => null,
                ])->save();
            } else {
                $failureCount = (int) $lockedProvider->consecutive_failures + 1;
                $failureThreshold = max(
                    1,
                    (int) config('gateway.provider_health.failure_threshold', 3),
                );
                $circuitSeconds = max(
                    1,
                    (int) config('gateway.provider_health.circuit_open_seconds', 300),
                );

                $lockedProvider->forceFill([
                    'consecutive_failures' => $failureCount,
                    'health_status' => $failureCount >= $failureThreshold
                        ? 'unavailable'
                        : 'degraded',
                    'circuit_open_until' => $failureCount >= $failureThreshold
                        ? now()->addSeconds($circuitSeconds)
                        : null,
                ])->save();
            }

            if ($previousStatus === (string) $lockedProvider->health_status) {
                return;
            }

            event(new ProviderHealthChanged(
                providerAccountId: (int) $lockedProvider->id,
                providerName: (string) $lockedProvider->name,
                providerSlug: (string) $lockedProvider->slug,
                providerDriver: (string) $lockedProvider->driver,
                providerUuid: (string) $lockedProvider->uuid,
                fromStatus: $previousStatus,
                toStatus: (string) $lockedProvider->health_status,
                consecutiveFailures: (int) $lockedProvider->consecutive_failures,
                circuitOpenUntilIso: $lockedProvider->circuit_open_until?->toIso8601String(),
                errorSummary: app(AlertErrorSummarizer::class)->summarize(
                    $result->errorCode,
                    $result->errorMessage,
                ),
            ));
        });
    }

    public function recordNumberCheck(ProviderAccount $provider, NumberCheckResult $result): void
    {
        if ($result->status === 'unsupported') {
            return;
        }

        if (in_array($result->status, ['registered', 'not_registered'], true)) {
            $this->record($provider, ProviderResult::accepted(
                httpStatus: $result->httpStatus,
            ));

            return;
        }

        $this->record($provider, ProviderResult::providerFailed(
            httpStatus: $result->httpStatus,
            errorCode: $result->reasonCode,
            errorMessage: 'Pengecekan nomor gagal diverifikasi.',
        ));
    }
}
