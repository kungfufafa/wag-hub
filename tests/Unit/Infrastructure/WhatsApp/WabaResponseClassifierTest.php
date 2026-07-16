<?php

namespace Tests\Unit\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderOutcome;
use App\Domain\Delivery\RetryDisposition;
use App\Infrastructure\WhatsApp\WabaResponseClassifier;
use PHPUnit\Framework\TestCase;

final class WabaResponseClassifierTest extends TestCase
{
    public function test_it_accepts_meta_response_and_extracts_the_wamid(): void
    {
        $result = (new WabaResponseClassifier)->classify(
            200,
            '{"messaging_product":"whatsapp","contacts":[{"wa_id":"6281234567890"}],"messages":[{"id":"wamid.123"}]}',
        );

        self::assertSame(ProviderOutcome::Accepted, $result->outcome);
        self::assertSame('wamid.123', $result->providerMessageId);
        self::assertFalse($result->allowsFallback());
    }

    public function test_indeterminate_success_is_unknown_without_fallback(): void
    {
        $result = (new WabaResponseClassifier)->classify(200, '{"messaging_product":"whatsapp"}');

        self::assertSame(ProviderOutcome::OutcomeUnknown, $result->outcome);
        self::assertSame(RetryDisposition::ReconcileOnly, $result->retryDisposition);
    }

    public function test_meta_authentication_error_allows_fallback(): void
    {
        $result = (new WabaResponseClassifier)->classify(
            400,
            '{"error":{"message":"Invalid OAuth access token","type":"OAuthException","code":190}}',
        );

        self::assertSame(ProviderOutcome::ProviderFailed, $result->outcome);
        self::assertSame('provider_authentication_failed', $result->errorCode);
        self::assertTrue($result->allowsFallback());
    }

    public function test_invalid_recipient_is_rejected_without_fallback(): void
    {
        $result = (new WabaResponseClassifier)->classify(
            400,
            '{"error":{"message":"Recipient phone number not in allowed list","code":131030}}',
        );

        self::assertSame(ProviderOutcome::Rejected, $result->outcome);
        self::assertFalse($result->allowsFallback());
    }

    public function test_server_failure_is_unknown_without_blind_fallback(): void
    {
        $result = (new WabaResponseClassifier)->classify(500, '{"error":{"message":"Internal error"}}');

        self::assertSame(ProviderOutcome::OutcomeUnknown, $result->outcome);
        self::assertFalse($result->allowsFallback());
    }
}
