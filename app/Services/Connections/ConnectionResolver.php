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
     * The connection POST /api/v2/messages sends from: the requested one, else the application's
     * default, else its only connection. Several connections without a default must be named.
     */
    public function forSend(ClientApplication $application, ?string $connectionId): WhatsAppConnection
    {
        if (filled($connectionId)) {
            return $this->resolve($application, (string) $connectionId);
        }

        $this->synchronizer->syncApplication($application);
        $connections = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        $default = $connections->firstWhere('is_default', true)
            ?? ($connections->count() === 1 ? $connections->first() : null);

        if ($default !== null) {
            return $this->health->hydrate($default);
        }

        if ($connections->isEmpty()) {
            throw new WhatsAppConnectionException(
                'This application has no WhatsApp connection yet. Connect one first.',
                404,
                'connection_not_found',
            );
        }

        throw new WhatsAppConnectionException(
            'This application has several WhatsApp connections and no default. Send "connection" with the connection id, or mark one as default.',
            422,
            'connection_required',
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
