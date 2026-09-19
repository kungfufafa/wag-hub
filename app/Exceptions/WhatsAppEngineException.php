<?php

namespace App\Exceptions;

use RuntimeException;

class WhatsAppEngineException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 500,
        public readonly string $errorCode = 'engine_error',
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
