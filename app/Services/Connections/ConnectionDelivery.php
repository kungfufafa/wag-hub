<?php

namespace App\Services\Connections;

/**
 * Outcome of POST /api/v2/connections/{id}/messages: the message snapshot, the HTTP status,
 * and, for a routed send no provider accepted, the v2 error object.
 */
final readonly class ConnectionDelivery
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $error
     */
    public function __construct(
        public array $data,
        public int $status,
        public ?array $error = null,
        public ?string $message = null,
    ) {}

    public function accepted(): bool
    {
        return $this->error === null;
    }
}
