<?php

namespace App\Services\Connections;

use App\Models\WhatsAppConnection;

final readonly class ProvisionedConnection
{
    public function __construct(
        public WhatsAppConnection $connection,
        public bool $created,
    ) {}
}
