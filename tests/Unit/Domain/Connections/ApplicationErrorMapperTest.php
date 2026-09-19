<?php

namespace Tests\Unit\Domain\Connections;

use App\Domain\Connections\ApplicationErrorCode;
use App\Services\Connections\ApplicationErrorMapper;
use Tests\TestCase;

class ApplicationErrorMapperTest extends TestCase
{
    public function test_maps_internal_failures_to_actionable_application_errors(): void
    {
        $mapper = new ApplicationErrorMapper;

        $this->assertSame(ApplicationErrorCode::AuthenticationFailed, $mapper->map('unauthenticated'));
        $this->assertSame(ApplicationErrorCode::AuthenticationFailed, $mapper->map('forbidden'));
        $this->assertSame(ApplicationErrorCode::RateLimited, $mapper->map('rate_limited'));
        $this->assertSame(ApplicationErrorCode::RecipientInvalid, $mapper->map('invalid_phone'));
        $this->assertSame(ApplicationErrorCode::ConnectionNotFound, $mapper->map('connection_not_found'));
        $this->assertSame(ApplicationErrorCode::ConnectionNotReady, $mapper->map('not_connected'));
        $this->assertSame(ApplicationErrorCode::ConnectionNotReady, $mapper->map('route_unavailable'));
        $this->assertSame(ApplicationErrorCode::CapabilityNotSupported, $mapper->map('attachment_format_unsupported'));
        $this->assertSame(ApplicationErrorCode::MessageExpired, $mapper->map('message_expired'));
        $this->assertSame(ApplicationErrorCode::DeliveryOutcomeUnknown, $mapper->map('provider_outcome_unknown'));
        $this->assertSame(ApplicationErrorCode::DeliveryOutcomeUnknown, $mapper->map('delivery_unknown'));
        $this->assertSame(ApplicationErrorCode::DeliveryFailed, $mapper->map('providers_failed'));
    }

    public function test_connection_oriented_payloads_use_action_codes_and_keep_internal_diagnostics(): void
    {
        $error = (new ApplicationErrorMapper)->applicationError(
            internalCode: 'not_connected',
            retryable: true,
            auditId: 'audit-1',
            connectionOriented: true,
        );

        $this->assertSame(ApplicationErrorCode::ConnectionNotReady->value, $error['code']);
        $this->assertTrue($error['retryable']);
        $this->assertSame('audit-1', $error['audit_id']);
        $this->assertSame('not_connected', $error['internal_code']);
    }

    public function test_legacy_payloads_keep_existing_error_codes_and_add_an_action(): void
    {
        $error = (new ApplicationErrorMapper)->applicationError(
            internalCode: 'providers_failed',
            retryable: true,
            auditId: 'audit-2',
            connectionOriented: false,
        );

        $this->assertSame('providers_failed', $error['code']);
        $this->assertSame(ApplicationErrorCode::DeliveryFailed->value, $error['action']);
        $this->assertSame('audit-2', $error['audit_id']);
    }
}
