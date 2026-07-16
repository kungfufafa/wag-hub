<?php

namespace App\Services;

use App\Infrastructure\WhatsApp\ProviderDriverManager;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class WhatsAppNumberChecker
{
    public function __construct(private ProviderDriverManager $drivers) {}

    /**
     * @return array<string, mixed>
     */
    public function check(ClientApplication $application, string $recipient, string $routeKey): array
    {
        $policyId = $this->resolvePolicyId($application, $routeKey);

        if ($policyId === null) {
            return ['error_code' => 'route_unavailable'];
        }

        $steps = DB::table('routing_steps')
            ->where('routing_policy_id', $policyId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $checks = [];
        $attempted = false;

        foreach ($steps as $step) {
            $provider = ProviderAccount::query()->find($step->provider_account_id);

            if ($provider === null) {
                continue;
            }

            $skipReason = $this->skipReason($provider, (bool) $step->is_active);

            if ($skipReason !== null) {
                $checks[] = $this->skippedCheck($provider, $skipReason);

                continue;
            }

            $attempted = true;
            $result = $this->drivers->checkNumber($provider, $recipient);
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
                return [
                    'status' => $result->status,
                    'registered' => $result->registered,
                    'checks' => $checks,
                ];
            }
        }

        if (! $attempted) {
            return ['error_code' => 'provider_unavailable', 'checks' => $checks];
        }

        $statuses = array_column($checks, 'status');

        return [
            'status' => in_array('unknown', $statuses, true) ? 'unknown' : 'unsupported',
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
}
