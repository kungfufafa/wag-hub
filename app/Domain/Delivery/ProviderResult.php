<?php

namespace App\Domain\Delivery;

final readonly class ProviderResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public ProviderOutcome $outcome,
        public DeliveryCertainty $deliveryCertainty,
        public RetryDisposition $retryDisposition,
        public ?int $httpStatus = null,
        public ?string $providerMessageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function accepted(
        ?int $httpStatus = null,
        ?string $providerMessageId = null,
        array $metadata = [],
    ): self {
        return new self(
            outcome: ProviderOutcome::Accepted,
            deliveryCertainty: DeliveryCertainty::Accepted,
            retryDisposition: RetryDisposition::DoNotRetry,
            httpStatus: $httpStatus,
            providerMessageId: $providerMessageId,
            metadata: $metadata,
        );
    }

    public static function rejected(
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        RetryDisposition $retryDisposition = RetryDisposition::FallbackAllowed,
    ): self {
        return new self(
            outcome: ProviderOutcome::Rejected,
            deliveryCertainty: DeliveryCertainty::NotSent,
            retryDisposition: $retryDisposition,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    public static function providerFailed(
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): self {
        return new self(
            outcome: ProviderOutcome::ProviderFailed,
            deliveryCertainty: DeliveryCertainty::NotSent,
            retryDisposition: RetryDisposition::FallbackAllowed,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    public static function outcomeUnknown(
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): self {
        return new self(
            outcome: ProviderOutcome::OutcomeUnknown,
            deliveryCertainty: DeliveryCertainty::Unknown,
            retryDisposition: RetryDisposition::ReconcileOnly,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    public function isAccepted(): bool
    {
        return $this->outcome === ProviderOutcome::Accepted;
    }

    public function allowsFallback(): bool
    {
        return $this->retryDisposition === RetryDisposition::FallbackAllowed;
    }
}
