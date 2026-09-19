<?php

namespace App\Services\Connection;

use App\Domain\Connection\ConnectionType;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\RoutingStep;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ConnectionBackfillService
{
    public function __construct(private readonly ConnectionStatusResolver $statusResolver) {}

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function backfill(?int $applicationId = null, bool $dryRun = false): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $applications = ClientApplication::query()
            ->when($applicationId !== null, fn ($query) => $query->whereKey($applicationId))
            ->orderBy('id')
            ->get();

        foreach ($applications as $application) {
            foreach ($this->managedCandidates($application) as $candidate) {
                $result = $this->upsertManaged($application, $candidate, $dryRun);

                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => $skipped++,
                };
            }

            foreach ($this->providerRouteCandidates($application) as $candidate) {
                $result = $this->upsertProviderRoute($application, $candidate, $dryRun);

                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => $skipped++,
                };
            }
        }

        return compact('created', 'updated', 'skipped');
    }

    /**
     * @return Collection<int, array{provider: ProviderAccount, session_id: string, policy: ?RoutingPolicy}>
     */
    private function managedCandidates(ClientApplication $application): Collection
    {
        return ProviderAccount::query()
            ->whereIn('driver', ['wag_hub', 'waha'])
            ->get()
            ->filter(function (ProviderAccount $provider) use ($application): bool {
                $config = $provider->configuration ?? [];

                return (string) ($config['owned_by_application_id'] ?? '') === (string) $application->getKey();
            })
            ->map(function (ProviderAccount $provider): array {
                $config = $provider->configuration ?? [];
                $sessionId = (string) ($config['engine_session_id'] ?? $config['cesa_session_id'] ?? 'primary');

                $policy = RoutingPolicy::query()
                    ->where('client_application_id', $provider->configuration['owned_by_application_id'] ?? null)
                    ->where('operation', 'message')
                    ->where('key', $sessionId)
                    ->first();

                return [
                    'provider' => $provider,
                    'session_id' => $sessionId,
                    'policy' => $policy,
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, array{provider: ProviderAccount, policy: RoutingPolicy}>
     */
    private function providerRouteCandidates(ClientApplication $application): Collection
    {
        $policies = RoutingPolicy::query()
            ->where('client_application_id', $application->getKey())
            ->where('operation', 'message')
            ->where('is_active', true)
            ->get();

        $candidates = collect();

        foreach ($policies as $policy) {
            $steps = RoutingStep::query()
                ->where('routing_policy_id', $policy->id)
                ->where('is_active', true)
                ->orderBy('position')
                ->get();

            $primaryStep = $steps->first();

            if ($primaryStep === null) {
                continue;
            }

            $provider = ProviderAccount::query()->find($primaryStep->provider_account_id);

            if ($provider === null || $provider->isUserLinkedSession()) {
                continue;
            }

            if (WhatsAppConnection::query()->where('routing_policy_id', $policy->id)->exists()) {
                continue;
            }

            $candidates->push([
                'provider' => $provider,
                'policy' => $policy,
            ]);
        }

        return $candidates->unique(fn (array $row): string => (string) $row['policy']->id)->values();
    }

    /**
     * @param  array{provider: ProviderAccount, session_id: string, policy: ?RoutingPolicy}  $candidate
     */
    private function upsertManaged(ClientApplication $application, array $candidate, bool $dryRun): string
    {
        $provider = $candidate['provider'];
        $sessionId = $candidate['session_id'];

        $existing = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('session_id', $sessionId)
            ->first();

        if ($existing !== null) {
            if ($existing->provider_account_id === null && ! $dryRun) {
                $existing->forceFill([
                    'provider_account_id' => $provider->id,
                    'routing_policy_id' => $candidate['policy']?->id,
                    'driver' => $provider->driver,
                ])->save();
                $this->statusResolver->refresh($existing);

                return 'updated';
            }

            return 'skipped';
        }

        if ($dryRun) {
            return 'created';
        }

        $connection = new WhatsAppConnection;
        $connection->forceFill([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $application->getKey(),
            'name' => $provider->name,
            'slug' => 'default-'.Str::lower(Str::random(4)),
            'type' => ConnectionType::ManagedNumber->value,
            'is_default' => ! WhatsAppConnection::query()->where('client_application_id', $application->getKey())->exists(),
            'provider_account_id' => $provider->id,
            'routing_policy_id' => $candidate['policy']?->id,
            'session_id' => $sessionId,
            'driver' => $provider->driver,
            'provisioning_state' => ['backfilled_at' => now()->toIso8601String()],
        ]);
        $connection->save();
        $this->statusResolver->refresh($connection);

        return 'created';
    }

    /**
     * @param  array{provider: ProviderAccount, policy: RoutingPolicy}  $candidate
     */
    private function upsertProviderRoute(ClientApplication $application, array $candidate, bool $dryRun): string
    {
        $provider = $candidate['provider'];
        $policy = $candidate['policy'];

        $existing = WhatsAppConnection::query()
            ->where('routing_policy_id', $policy->id)
            ->first();

        if ($existing !== null) {
            return 'skipped';
        }

        if ($dryRun) {
            return 'created';
        }

        $connection = new WhatsAppConnection;
        $connection->forceFill([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $application->getKey(),
            'name' => $policy->name,
            'slug' => Str::slug($policy->key) ?: 'route-'.Str::lower(Str::random(4)),
            'type' => ConnectionType::ProviderRoute->value,
            'is_default' => $policy->is_default
                && ! WhatsAppConnection::query()->where('client_application_id', $application->getKey())->where('is_default', true)->exists(),
            'provider_account_id' => $provider->id,
            'routing_policy_id' => $policy->id,
            'driver' => $provider->driver,
            'provisioning_state' => ['backfilled_at' => now()->toIso8601String()],
        ]);
        $connection->save();
        $this->statusResolver->refresh($connection);

        return 'created';
    }
}
