<?php

namespace Tests\Unit\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderOutcome;
use App\Domain\Delivery\RetryDisposition;
use App\Infrastructure\WhatsApp\FonnteResponseClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FonnteResponseClassifierTest extends TestCase
{
    public function test_it_requires_status_true_and_retains_accepted_metadata(): void
    {
        $result = (new FonnteResponseClassifier)->classify(
            httpStatus: 200,
            body: json_encode([
                'status' => true,
                'id' => ['fonnte-message-123'],
                'process' => 'pending',
                'requestid' => 98765,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(ProviderOutcome::Accepted, $result->outcome);
        self::assertSame('fonnte-message-123', $result->providerMessageId);
        self::assertSame('pending', $result->metadata['process']);
        self::assertSame(98765, $result->metadata['requestid']);
        self::assertFalse($result->allowsFallback());
    }

    #[DataProvider('falseStatusVariants')]
    public function test_false_status_variants_are_never_accepted(bool|int|string $status): void
    {
        $result = (new FonnteResponseClassifier)->classify(
            httpStatus: 200,
            body: json_encode([
                'status' => $status,
                'reason' => 'Invalid target',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(ProviderOutcome::Rejected, $result->outcome);
        self::assertSame(RetryDisposition::DoNotRetry, $result->retryDisposition);
        self::assertFalse($result->allowsFallback());
    }

    /**
     * @return iterable<string, array{bool|int|string}>
     */
    public static function falseStatusVariants(): iterable
    {
        yield 'boolean false' => [false];
        yield 'string false' => ['false'];
        yield 'integer zero' => [0];
        yield 'string zero' => ['0'];
    }

    #[DataProvider('ambiguousSuccessfulResponses')]
    public function test_an_indeterminate_2xx_response_is_unknown_and_cannot_fallback(string $body): void
    {
        $result = (new FonnteResponseClassifier)->classify(httpStatus: 200, body: $body);

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
        yield 'malformed JSON' => ['{"status":'];
        yield 'empty object' => ['{}'];
        yield 'missing status' => ['{"id":"not-enough-to-accept","process":"pending"}'];
        yield 'non-boolean success marker' => ['{"status":"true"}'];
    }

    public function test_a_client_payload_error_is_a_message_rejection_without_fallback(): void
    {
        $result = (new FonnteResponseClassifier)->classify(
            httpStatus: 422,
            body: '{"status":false,"reason":"Invalid target"}',
        );

        self::assertSame(ProviderOutcome::Rejected, $result->outcome);
        self::assertSame(RetryDisposition::DoNotRetry, $result->retryDisposition);
        self::assertFalse($result->allowsFallback());
    }

    public function test_a_server_error_is_ambiguous_and_never_triggers_blind_fallback(): void
    {
        $result = (new FonnteResponseClassifier)->classify(
            httpStatus: 503,
            body: '{"status":false,"reason":"Upstream failed after processing began"}',
        );

        self::assertSame(ProviderOutcome::OutcomeUnknown, $result->outcome);
        self::assertSame(RetryDisposition::ReconcileOnly, $result->retryDisposition);
        self::assertFalse($result->allowsFallback());
    }

    #[DataProvider('providerAccountFailureStatuses')]
    public function test_provider_or_account_http_errors_allow_fallback(int $httpStatus): void
    {
        $result = (new FonnteResponseClassifier)->classify(
            httpStatus: $httpStatus,
            body: '{"status":false,"reason":"Provider unavailable"}',
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
    }
}
