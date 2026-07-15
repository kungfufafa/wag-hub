<?php

namespace App\Support;

use LogicException;

final class PayloadHasher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload, string $key): string
    {
        if (trim($key) === '') {
            throw new LogicException('A secret payload fingerprint key is required.');
        }

        return hash_hmac('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ), $key);
    }
}
