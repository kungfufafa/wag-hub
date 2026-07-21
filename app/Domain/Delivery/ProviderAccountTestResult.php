<?php

namespace App\Domain\Delivery;

final readonly class ProviderAccountTestResult
{
    public function __construct(
        public bool $success,
        public string $title,
        public string $body,
        public string $type,
    ) {}
}
