<?php

namespace App\Domain\NumberCheck;

final readonly class NumberCheckResult
{
    private function __construct(
        public string $status,
        public ?bool $registered,
        public ?string $reasonCode = null,
    ) {}

    public static function registered(): self
    {
        return new self('registered', true);
    }

    public static function notRegistered(): self
    {
        return new self('not_registered', false);
    }

    public static function unknown(string $reasonCode = 'provider_response_invalid'): self
    {
        return new self('unknown', null, $reasonCode);
    }

    public static function unsupported(): self
    {
        return new self('unsupported', null, 'provider_check_unsupported');
    }
}
