<?php

namespace App\Services;

use App\Domain\NumberCheck\NumberCheckResult;
use App\Infrastructure\WhatsApp\ProviderDriverManager;
use App\Models\ClientApplication;
use App\Models\NumberCheckAttempt;
use App\Models\NumberCheckRequest;
use App\Models\ProviderAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class WhatsAppNumberChecker
{
    public function __construct(private ProviderDriverManager $drivers) {}

    /**
     * @return array<string, mixed>
     */
    public function check(
        ClientApplication $application,
        string $recipient,
        string $routeKey,
        NumberCheckRequest $audit,
    ): array {
        $policyId = $this->resolvePolicyId($application, $routeKey);

        if ($policyId === null) {
            $this->finishAudit($audit, 'failed', null, errorCode: 'route_unavailable');

            return ['error_code' => 'route_unavailable'];
        }

        $audit->forceFill(['routing_policy_id' => $policyId])->save();

        $steps = DB::table('routing_steps')
            ->where('routing_policy_id', $policyId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $checks = [];
        $attempted = false;
        $sequence = 0;

        foreach ($steps as $step) {
            $provider = ProviderAccount::withTrashed()->find($step->provider_account_id);

            if ($provider === null) {
                continue;
            }

            $skipReason = $this->skipReason($provider, (bool) $step->is_active);
            $sequence++;

            if ($skipReason !== null) {
                $checks[] = $this->skippedCheck($provider, $skipReason);
                $now = now();
                $this->recordAttempt(
                    audit: $audit,
                    provider: $provider,
                    sequence: $sequence,
                    result: NumberCheckResult::unknown($skipReason),
                    status: 'skipped',
                    startedAt: $now,
                    finishedAt: $now,
                );

                continue;
            }

            $attempted = true;
            $startedAt = now();
            $started = hrtime(true);

            try {
                $result = $this->drivers->checkNumber($provider, $recipient);
            } catch (Throwable) {
                $result = NumberCheckResult::unknown('provider_exception');
            }

            $latencyMs = max(0, (int) floor((hrtime(true) - $started) / 1_000_000));
            $finishedAt = now();
            $this->recordAttempt(
                audit: $audit,
                provider: $provider,
                sequence: $sequence,
                result: $result,
                status: $result->status,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                latencyMs: $latencyMs,
            );
            $check = [
                'provider' => (string) $provider->slug,
                'driver' => (string) $provider->driver,
                'status' => $result->status,
                'registered' => $result->registered,
            ];

            if ($result->reasonCode !== null) {
                $check['reason_code'] = $result->reasonCode;
            }

            $checks[] = $check;

            if (in_array($result->status, ['registered', 'not_registered'], true)) {
                $this->finishAudit(
                    $audit,
                    $result->status,
                    $result->registered,
                    resolvedProvider: $provider,
                );

                return [
                    'status' => $result->status,
                    'registered' => $result->registered,
                    'checks' => $checks,
                ];
            }
        }

        if (! $attempted) {
            $this->finishAudit($audit, 'failed', null, errorCode: 'provider_unavailable');

            return ['error_code' => 'provider_unavailable', 'checks' => $checks];
        }

        $statuses = array_column($checks, 'status');
        $status = in_array('unknown', $statuses, true) ? 'unknown' : 'unsupported';
        $this->finishAudit($audit, $status, null);

        return [
            'status' => $status,
            'registered' => null,
            'checks' => $checks,
        ];
    }

    private function resolvePolicyId(ClientApplication $application, string $routeKey): ?int
    {
        foreach ([$application->getKey(), null] as $applicationId) {
            $exact = $this->policyQuery($applicationId)
                ->where('key', $routeKey)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            if ($exact !== null) {
                return (int) $exact;
            }

            $default = $this->policyQuery($applicationId)
                ->where('is_default', true)
                ->orderBy('id')
                ->value('id');

            if ($default !== null) {
                return (int) $default;
            }
        }

        return null;
    }

    private function policyQuery(mixed $applicationId): Builder
    {
        $query = DB::table('routing_policies')
            ->where('operation', 'number_check')
            ->where('is_active', true)
            ->whereNull('deleted_at');

        if ($applicationId === null) {
            return $query->whereNull('client_application_id');
        }

        return $query->where('client_application_id', $applicationId);
    }

    private function skipReason(ProviderAccount $provider, bool $stepActive): ?string
    {
        if ($provider->trashed()) {
            return 'provider_deleted';
        }

        if (! $stepActive) {
            return 'route_step_inactive';
        }

        if (! (bool) $provider->is_active) {
            return 'provider_inactive';
        }

        if ($provider->circuit_open_until !== null && now()->lessThan($provider->circuit_open_until)) {
            return 'provider_circuit_open';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function skippedCheck(ProviderAccount $provider, string $reasonCode): array
    {
        return [
            'provider' => (string) $provider->slug,
            'driver' => (string) $provider->driver,
            'status' => 'skipped',
            'registered' => null,
            'reason_code' => $reasonCode,
        ];
    }

    private function recordAttempt(
        NumberCheckRequest $audit,
        ProviderAccount $provider,
        int $sequence,
        NumberCheckResult $result,
        string $status,
        mixed $startedAt,
        mixed $finishedAt,
        ?int $latencyMs = null,
    ): void {
        NumberCheckAttempt::forceCreate([
            'number_check_request_id' => $audit->getKey(),
            'provider_account_id' => $provider->getKey(),
            'sequence' => $sequence,
            'status' => $status,
            'registered' => $status === 'skipped' ? null : $result->registered,
            'http_status' => $result->httpStatus,
            'reason_code' => $result->reasonCode,
            'latency_ms' => $latencyMs,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
    }

    private function finishAudit(
        NumberCheckRequest $audit,
        string $status,
        ?bool $registered,
        ?ProviderAccount $resolvedProvider = null,
        ?string $errorCode = null,
    ): void {
        $audit->forceFill([
            'resolved_provider_account_id' => $resolvedProvider?->getKey(),
            'status' => $status,
            'registered' => $registered,
            'last_error_code' => $errorCode,
            'finished_at' => now(),
        ])->save();
    }
}
