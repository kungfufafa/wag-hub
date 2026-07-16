<?php

namespace Tests\Unit\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderOutcome;
use App\Domain\Delivery\RetryDisposition;
use App\Infrastructure\WhatsApp\GowaResponseClassifier;
use PHPUnit\Framework\TestCase;

final class GowaResponseClassifierTest extends TestCase
{
    public function test_it_accepts_success_and_extracts_the_message_id(): void
    {
        $result = (new GowaResponseClassifier)->classify(
            200,
            '{"code":"SUCCESS","message":"Success","results":{"message_id":"gowa-message-1","status":"sent"}}',
        );

        self::assertSame(ProviderOutcome::Accepted, $result->outcome);
        self::assertSame('gowa-message-1', $result->providerMessageId);
        self::assertFalse($result->allowsFallback());
    }

    public function test_indeterminate_success_is_unknown_without_fallback(): void
    {
        $result = (new GowaResponseClassifier)->classify(200, '{"code":"SUCCESS"}');

        self::assertSame(ProviderOutcome::OutcomeUnknown, $result->outcome);
        self::assertSame(RetryDisposition::ReconcileOnly, $result->retryDisposition);
    }

    public function test_authentication_failure_allows_fallback(): void
    {
        $result = (new GowaResponseClassifier)->classify(401, '{"code":"UNAUTHORIZED","message":"invalid credentials"}');

        self::assertSame(ProviderOutcome::ProviderFailed, $result->outcome);
        self::assertTrue($result->allowsFallback());
    }

    public function test_server_failure_is_unknown_without_blind_fallback(): void
    {
        $result = (new GowaResponseClassifier)->classify(503, '{"code":"INTERNAL_ERROR"}');

        self::assertSame(ProviderOutcome::OutcomeUnknown, $result->outcome);
        self::assertFalse($result->allowsFallback());
    }
}
