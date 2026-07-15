<?php

namespace Tests\Unit\Infrastructure\WhatsApp;

use App\Domain\Delivery\DeliveryCertainty;
use App\Domain\Delivery\ProviderOutcome;
use App\Domain\Delivery\RetryDisposition;
use App\Infrastructure\WhatsApp\TransportFailureClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransportFailureClassifierTest extends TestCase
{
    #[DataProvider('definitivePreTransmissionFailures')]
    public function test_a_failure_before_transmission_allows_safe_fallback(
        string $errorCode,
        string $errorMessage,
    ): void {
        $result = (new TransportFailureClassifier)->classify(
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            requestMayHaveBeenSent: false,
        );

        self::assertSame(ProviderOutcome::ProviderFailed, $result->outcome);
        self::assertSame(DeliveryCertainty::NotSent, $result->deliveryCertainty);
        self::assertSame(RetryDisposition::FallbackAllowed, $result->retryDisposition);
        self::assertTrue($result->allowsFallback());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function definitivePreTransmissionFailures(): iterable
    {
        yield 'connection refused' => ['connection_refused', 'Could not connect to provider.'];
        yield 'DNS lookup failed' => ['dns_failure', 'Could not resolve provider host.'];
    }

    #[DataProvider('ambiguousTransportFailures')]
    public function test_a_failure_after_a_possible_write_is_unknown_and_stops_fallback(
        string $errorCode,
        string $errorMessage,
    ): void {
        $result = (new TransportFailureClassifier)->classify(
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            requestMayHaveBeenSent: true,
        );

        self::assertSame(ProviderOutcome::OutcomeUnknown, $result->outcome);
        self::assertSame(DeliveryCertainty::Unknown, $result->deliveryCertainty);
        self::assertSame(RetryDisposition::ReconcileOnly, $result->retryDisposition);
        self::assertFalse($result->allowsFallback());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ambiguousTransportFailures(): iterable
    {
        yield 'timeout after possible write' => ['transport_timeout', 'Provider response timed out.'];
        yield 'connection reset after possible write' => ['connection_reset', 'Connection reset by peer.'];
    }
}
