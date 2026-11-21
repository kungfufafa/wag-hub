<?php

namespace App\Exceptions;

use RuntimeException;

class WhatsAppConnectionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 422,
        public readonly string $errorCode = 'connection_not_ready',
        public readonly bool $retryable = false,
        public readonly ?string $auditId = null,
    ) {
        parent::__construct($message);
    }
}
