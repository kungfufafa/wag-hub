<?php

namespace App\Services\Connections;

use App\Domain\Connections\ConnectionStatus;
use App\Domain\Connections\ConnectionType;
use App\Models\ClientApplication;
use App\Models\EngineSession;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConnectionSynchronizer
{
    public function __construct(private ConnectionCapabilityCatalog $capabilities) {}

    public function syncApplication(ClientApplication $application): void
    {
        DB::transaction(function () use ($application): void {
            ClientApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            $sessions = EngineSession::query()
                ->where('client_application_id', $application->getKey())
                ->orderBy('id')
                ->get();

            foreach ($sessions as $session) {
                $this->projectSession($application, $session);
            }

            $this->projectDefaultRoute($application);
            $this->ensureDefault($application);
        });
    }

    /**
     * Every live v2 engine session, including sessions mapped by engine:migrate-legacy, is one
     * managed-number connection. Re-running is a no-op once the session is linked.
     */
    private function projectSession(ClientApplication $application, EngineSession $session): void
    {
        $linked = WhatsAppConnection::withTrashed()->where('engine_session_id', $session->getKey())->exists();

        if ($linked) {
            return;
        }

        $legacy = $this->legacyConnectionFor($application, $session);

        if ($legacy !== null) {
            $legacy->forceFill([
                'engine_session_id' => $session->getKey(),
                'setup_state' => array_merge($legacy->setup_state ?? [], ['last_step' => 'synced']),
            ])->save();

            return;
        }

        $connection = new WhatsAppConnection;
        $connection->forceFill([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $application->getKey(),
            'name' => $session->name,
            'type' => ConnectionType::ManagedNumber->value,
            'engine_session_id' => $session->getKey(),
            'status' => ConnectionStatus::SetupRequired->value,
            'is_default' => false,
            'capabilities' => $this->capabilities->forEngineSession(),
            'setup_state' => ['last_step' => 'synced'],
        ]);
        $connection->save();
    }

    /**
     * A connection projected before the v2 migration points at the legacy native account; link it
     * to the session that engine:migrate-legacy created for that account instead of duplicating it.
     */
    private function legacyConnectionFor(ClientApplication $application, EngineSession $session): ?WhatsAppConnection
    {
        $candidates = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('type', ConnectionType::ManagedNumber->value)
            ->whereNull('engine_session_id')
            ->whereNotNull('provider_account_id')
            ->with('providerAccount')
            ->get();

        return $candidates->first(function (WhatsAppConnection $connection) use ($session): bool {
            $account = $connection->providerAccount;

            if (! $account instanceof ProviderAccount || $account->driver !== 'wag_hub') {
                return false;
            }

            $reference = $account->configuration['engine_session_id'] ?? $account->configuration['cesa_session_id'] ?? null;

            return $session->runner_id === 'wgh-'.$account->uuid
                || ($session->external_reference !== null && $reference === $session->external_reference);
        });
    }

    private function projectDefaultRoute(ClientApplication $application): void
    {
        $policy = RoutingPolicy::query()
            ->where('client_application_id', $application->getKey())
            ->where('operation', 'message')
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($policy === null) {
            return;
        }

        $already = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('routing_policy_id', $policy->getKey())
            ->exists();

        if ($already) {
            return;
        }

        $step = $policy->steps()->where('is_active', true)->orderBy('position')->first();
        $account = $step?->providerAccount;

        // A native number is projected through its engine session, never as a provider route.
        if ($account?->isUserLinkedSession()) {
            return;
        }

        $connection = new WhatsAppConnection;
        $connection->forceFill([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $application->getKey(),
            'name' => $policy->name ?: 'Delivery',
            'type' => ConnectionType::ProviderRoute->value,
            'status' => ConnectionStatus::SetupRequired->value,
            'is_default' => false,
            'provider_account_id' => $account?->getKey(),
            'routing_policy_id' => $policy->getKey(),
            'capabilities' => $this->capabilities->union(
                $policy->steps->map(fn ($step): string => (string) ($step->providerAccount?->driver ?? ''))->all(),
            ),
            'setup_state' => ['last_step' => 'synced'],
        ]);
        $connection->save();
    }

    private function ensureDefault(ClientApplication $application): void
    {
        $hasDefault = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('is_default', true)
            ->exists();

        if ($hasDefault) {
            return;
        }

        $first = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->orderBy('id')
            ->first();

        $first?->forceFill(['is_default' => true])->save();
    }
}
