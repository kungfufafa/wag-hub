<?php

namespace App\Models;

final readonly class IssuedApiCredential
{
    public function __construct(
        public ApiCredential $credential,
        public string $plainTextToken,
    ) {}
}
