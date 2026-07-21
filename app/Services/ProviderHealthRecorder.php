<?php

namespace App\Services;

use App\Domain\Delivery\ProviderOutcome;
use App\Domain\Delivery\ProviderResult;
use App\Domain\NumberCheck\NumberCheckResult;
use App\Models\ProviderAccount;
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

            if ($result->outcome === ProviderOutcome::Accepted) {
                $lockedProvider->forceFill([
                    'consecutive_failures' => 0,
                    'health_status' => 'healthy',
                    'circuit_open_until' => null,
                ])->save();

                return;
            }

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
