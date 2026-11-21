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

    /**
     * Resolve a connection owned by the application. Another tenant's connection is
     * indistinguishable from a missing one.
     */
    public function resolve(ClientApplication $application, string $connectionId): WhatsAppConnection
    {
        $connection = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('uuid', $connectionId)
            ->first();

        if ($connection === null) {
            throw new WhatsAppConnectionException(
                'WhatsApp connection was not found.',
                404,
                'connection_not_found',
            );
        }

        return $this->health->hydrate($connection);
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
