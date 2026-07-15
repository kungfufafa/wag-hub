<?php

namespace Tests\Unit\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderOutcome;
use App\Domain\Delivery\RetryDisposition;
use App\Infrastructure\WhatsApp\WahaResponseClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WahaResponseClassifierTest extends TestCase
{
    public function test_it_accepts_an_explicit_success_response_and_extracts_the_remote_id(): void
    {
        $result = (new WahaResponseClassifier)->classify(
            httpStatus: 201,
            body: '{"id":"waha-message-123","status":"success","timestamp":1721012400}',
        );

        self::assertSame(ProviderOutcome::Accepted, $result->outcome);
        self::assertSame('waha-message-123', $result->providerMessageId);
        self::assertSame(201, $result->httpStatus);
        self::assertFalse($result->allowsFallback());
    }

    public function test_it_accepts_a_processable_success_response_without_a_remote_id(): void
    {
        $result = (new WahaResponseClassifier)->classify(
            httpStatus: 200,
            body: '{"status":"success","timestamp":1721012400}',
        );

        self::assertSame(ProviderOutcome::Accepted, $result->outcome);
        self::assertNull($result->providerMessageId);
    }

    #[DataProvider('ambiguousSuccessfulResponses')]
    public function test_an_indeterminate_2xx_response_is_unknown_and_cannot_fallback(string $body): void
    {
        $result = (new WahaResponseClassifier)->classify(httpStatus: 200, body: $body);

        self::assertSame(ProviderOutcome::OutcomeUnknown, $result->outcome);
        self::assertSame(RetryDisposition::ReconcileOnly, $result->retryDisposition);
        self::assertFalse($result->allowsFallback());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousSuccessfulResponses(): iterable
    {
        yield 'empty body' => [''];
        yield 'malformed JSON' => ['{"id":'];
        yield 'empty object' => ['{}'];
        yield 'empty list' => ['[]'];
        yield 'JSON null' => ['null'];
    }

    public function test_a_client_payload_error_is_a_message_rejection_without_fallback(): void
    {
        $result = (new WahaResponseClassifier)->classify(
            httpStatus: 400,
            body: '{"error":"Invalid chatId"}',
        );

        self::assertSame(ProviderOutcome::Rejected, $result->outcome);
        self::assertSame(RetryDisposition::DoNotRetry, $result->retryDisposition);
        self::assertFalse($result->allowsFallback());
    }

    #[DataProvider('providerAccountFailureStatuses')]
    public function test_provider_or_account_http_errors_allow_fallback(int $httpStatus): void
    {
        $result = (new WahaResponseClassifier)->classify(
            httpStatus: $httpStatus,
            body: '{"error":"Provider unavailable"}',
        );

        self::assertSame(ProviderOutcome::ProviderFailed, $result->outcome);
        self::assertSame(RetryDisposition::FallbackAllowed, $result->retryDisposition);
        self::assertTrue($result->allowsFallback());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function providerAccountFailureStatuses(): iterable
    {
        yield 'authentication failure' => [401];
        yield 'authorization failure' => [403];
        yield 'rate limited' => [429];
        yield 'provider server error' => [503];
    }
}
