<?php

namespace App\Services\Connections;

use App\Exceptions\WhatsAppConnectionException;
use App\Models\ClientApplication;
use App\Models\WhatsAppConnection;

final class ConnectionResolver
{
    public function __construct(
        private ConnectionHealthProjector $health,
        private ConnectionSynchronizer $synchronizer,
    ) {}

    public function resolve(ClientApplication $application, ?string $connectionId = null): WhatsAppConnection
    {
        $this->synchronizer->syncApplication($application);

        $query = WhatsAppConnection::query()->where('client_application_id', $application->getKey());

        if (is_string($connectionId) && $connectionId !== '') {
            $connection = (clone $query)->where('uuid', $connectionId)->first();

            if ($connection === null) {
                throw new WhatsAppConnectionException(
                    'WhatsApp connection was not found.',
                    404,
                    'connection_not_found',
                );
            }

            return $this->health->hydrate($connection);
        }

        $default = (clone $query)->where('is_default', true)->first();

        if ($default !== null) {
            return $this->health->hydrate($default);
        }

        $only = $query->get();

        if ($only->count() === 1) {
            return $this->health->hydrate($only->first());
        }

        throw new WhatsAppConnectionException(
            'No default WhatsApp connection is available. Create one or pass connection_id.',
            409,
            'connection_not_ready',
            true,
        );
    }

    /**
     * @return list<WhatsAppConnection>
     */
    public function list(ClientApplication $application): array
    {
        $this->synchronizer->syncApplication($application);

        return WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (WhatsAppConnection $connection): WhatsAppConnection => $this->health->hydrate($connection))
            ->all();
    }
}
