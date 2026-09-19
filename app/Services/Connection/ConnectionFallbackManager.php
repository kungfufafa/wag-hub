<?php

namespace App\Services\Connection;

use App\Exceptions\ConnectionException;
use App\Models\ProviderAccount;
use App\Models\RoutingStep;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\DB;

final class ConnectionFallbackManager
{
    /**
     * @return list<array{id: int, slug: string, driver: string, position: int}>
     */
    public function listSteps(WhatsAppConnection $connection): array
    {
        if ($connection->routing_policy_id === null) {
            return [];
        }

        return RoutingStep::query()
            ->where('routing_policy_id', $connection->routing_policy_id)
            ->orderBy('position')
            ->with('providerAccount')
            ->get()
            ->map(function (RoutingStep $step): array {
                return [
                    'id' => (int) $step->provider_account_id,
                    'slug' => (string) ($step->providerAccount?->slug ?? ''),
                    'driver' => (string) ($step->providerAccount?->driver ?? ''),
                    'position' => (int) $step->position,
                ];
            })
            ->all();
    }

    public function addFallback(WhatsAppConnection $connection, ProviderAccount $provider, ?int $position = null): WhatsAppConnection
    {
        if (! $connection->isProviderRoute()) {
            throw new ConnectionException(
                'Fallback hanya tersedia untuk koneksi provider eksternal.',
                422,
                'capability_not_supported',
            );
        }

        if ($connection->routing_policy_id === null) {
            throw new ConnectionException('Koneksi belum memiliki routing policy.', 422, 'connection_not_ready');
        }

        DB::transaction(function () use ($connection, $provider, $position): void {
            $maxPosition = (int) RoutingStep::query()
                ->where('routing_policy_id', $connection->routing_policy_id)
                ->max('position');

            RoutingStep::query()->updateOrCreate(
                [
                    'routing_policy_id' => $connection->routing_policy_id,
                    'provider_account_id' => $provider->id,
                ],
                [
                    'position' => $position ?? ($maxPosition + 1),
                    'is_active' => true,
                ],
            );
        });

        return $connection->fresh() ?? $connection;
    }

    public function removeFallback(WhatsAppConnection $connection, int $providerAccountId): WhatsAppConnection
    {
        if ($connection->routing_policy_id === null) {
            throw new ConnectionException('Koneksi belum memiliki routing policy.', 422, 'connection_not_ready');
        }

        $steps = RoutingStep::query()
            ->where('routing_policy_id', $connection->routing_policy_id)
            ->orderBy('position')
            ->get();

        if ($steps->count() <= 1) {
            throw new ConnectionException('Koneksi harus memiliki minimal satu provider.', 422, 'capability_not_supported');
        }

        $target = $steps->firstWhere('provider_account_id', $providerAccountId);

        if ($target === null) {
            throw new ConnectionException('Provider fallback tidak ditemukan pada koneksi ini.', 404, 'connection_not_ready');
        }

        if ((int) $target->position === 1) {
            throw new ConnectionException('Provider utama tidak dapat dihapus dari fallback.', 422, 'capability_not_supported');
        }

        $target->delete();

        return $connection->fresh() ?? $connection;
    }
}
