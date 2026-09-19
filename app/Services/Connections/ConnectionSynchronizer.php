<?php

namespace App\Services\Connections;

use App\Domain\Connections\ConnectionStatus;
use App\Domain\Connections\ConnectionType;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\WhatsAppConnection;
use App\Services\WhatsAppEngineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConnectionSynchronizer
{
    public function __construct(
        private WhatsAppEngineService $engine,
        private ConnectionCapabilityCatalog $capabilities,
    ) {}

    public function syncApplication(ClientApplication $application): void
    {
        DB::transaction(function () use ($application): void {
            ClientApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            foreach ($this->engine->applicationSessions($application) as $account) {
                $this->projectManaged($application, $account);
            }

            $this->projectDefaultRoute($application);
            $this->ensureDefault($application);
        });
    }

    private function projectManaged(ClientApplication $application, ProviderAccount $account): void
    {
        $config = $account->configuration ?? [];
        $sessionId = $config['engine_session_id'] ?? $config['cesa_session_id'] ?? null;

        $existing = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('provider_account_id', $account->getKey())
            ->first();

        if ($existing !== null) {
            return;
        }

        $connection = new WhatsAppConnection;
        $connection->forceFill([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $application->getKey(),
            'name' => $account->name,
            'type' => ConnectionType::ManagedNumber->value,
            'status' => ConnectionStatus::SetupRequired->value,
            'is_default' => false,
            'provider_account_id' => $account->getKey(),
            'routing_policy_id' => is_string($sessionId)
                ? $this->engine->sessionRouteIdFor($application, $sessionId, $account)
                : null,
            'capabilities' => $this->capabilities->forDriver((string) $account->driver),
            'setup_state' => [
                'session_id' => $sessionId,
                'last_step' => 'synced',
            ],
        ]);
        $connection->save();
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

        $linkedSession = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('type', ConnectionType::ManagedNumber->value)
            ->where('routing_policy_id', $policy->getKey())
            ->exists();

        if ($linkedSession) {
            return;
        }

        $step = $policy->steps()->where('is_active', true)->orderBy('position')->first();
        $account = $step?->providerAccount;

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
