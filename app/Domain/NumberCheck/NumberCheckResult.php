<?php

namespace App\Domain\NumberCheck;

final readonly class NumberCheckResult
{
    private function __construct(
        public string $status,
        public ?bool $registered,
        public ?string $reasonCode = null,
        public ?int $httpStatus = null,
    ) {}

    public static function registered(?int $httpStatus = null): self
    {
        return new self('registered', true, httpStatus: $httpStatus);
    }

    public static function notRegistered(?int $httpStatus = null): self
    {
        return new self('not_registered', false, httpStatus: $httpStatus);
    }

    public static function unknown(
        string $reasonCode = 'provider_response_invalid',
        ?int $httpStatus = null,
    ): self {
        return new self('unknown', null, $reasonCode, $httpStatus);
    }

    public static function unsupported(): self
    {
        return new self('unsupported', null, 'provider_check_unsupported');
    }
}
