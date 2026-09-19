<?php

namespace App\Exceptions;

use RuntimeException;

final class ConnectionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
        public readonly string $errorCode = 'connection_error',
        public readonly bool $retryable = false,
        public readonly ?string $nextAction = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toErrorPayload(): array
    {
        $payload = [
            'code' => $this->errorCode,
            'retryable' => $this->retryable,
        ];

        if ($this->nextAction !== null) {
            $payload['next_action'] = $this->nextAction;
        }

        return $payload;
    }
}
