<?php

namespace Tests\Unit\Domain\Delivery;

use App\Domain\Delivery\DeliveryCertainty;
use App\Domain\Delivery\ProviderOutcome;
use App\Domain\Delivery\ProviderResult;
use App\Domain\Delivery\RetryDisposition;
use PHPUnit\Framework\TestCase;

final class ProviderResultTest extends TestCase
{
    public function test_an_accepted_result_is_terminal_and_retains_provider_metadata(): void
    {
        $result = ProviderResult::accepted(
            httpStatus: 201,
            providerMessageId: 'remote-message-123',
            metadata: ['process' => 'queued'],
        );

        self::assertSame(ProviderOutcome::Accepted, $result->outcome);
        self::assertSame(DeliveryCertainty::Accepted, $result->deliveryCertainty);
        self::assertSame(RetryDisposition::DoNotRetry, $result->retryDisposition);
        self::assertSame(201, $result->httpStatus);
        self::assertSame('remote-message-123', $result->providerMessageId);
        self::assertSame(['process' => 'queued'], $result->metadata);
        self::assertFalse($result->allowsFallback());
    }

    public function test_a_message_rejection_allows_safe_fallback_when_not_sent(): void
    {
        $result = ProviderResult::rejected(
            httpStatus: 422,
            errorCode: 'invalid_recipient',
            errorMessage: 'Recipient is invalid.',
        );

        self::assertSame(ProviderOutcome::Rejected, $result->outcome);
        self::assertSame(DeliveryCertainty::NotSent, $result->deliveryCertainty);
        self::assertSame(RetryDisposition::FallbackAllowed, $result->retryDisposition);
        self::assertSame('invalid_recipient', $result->errorCode);
        self::assertTrue($result->allowsFallback());
    }

    public function test_a_definitive_provider_failure_allows_safe_fallback(): void
    {
        $result = ProviderResult::providerFailed(
            httpStatus: 503,
            errorCode: 'provider_unavailable',
            errorMessage: 'Provider is unavailable.',
        );

        self::assertSame(ProviderOutcome::ProviderFailed, $result->outcome);
        self::assertSame(DeliveryCertainty::NotSent, $result->deliveryCertainty);
        self::assertSame(RetryDisposition::FallbackAllowed, $result->retryDisposition);
        self::assertTrue($result->allowsFallback());
    }

    public function test_an_unknown_outcome_requires_reconciliation_and_never_fallback(): void
    {
        $result = ProviderResult::outcomeUnknown(
            httpStatus: null,
            errorCode: 'transport_timeout',
            errorMessage: 'Request may have reached the provider.',
        );

        self::assertSame(ProviderOutcome::OutcomeUnknown, $result->outcome);
        self::assertSame(DeliveryCertainty::Unknown, $result->deliveryCertainty);
        self::assertSame(RetryDisposition::ReconcileOnly, $result->retryDisposition);
        self::assertFalse($result->allowsFallback());
    }
}
