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

    public function test_connection_errors_report_outages_as_retryable_not_ready(): void
    {
        $mapper = new ApplicationErrorMapper;

        $this->assertSame(
            ['code' => 'connection_not_ready', 'retryable' => true, 'internal_code' => 'engine_unreachable'],
            $mapper->forConnection('engine_unreachable', false),
        );
        $this->assertSame(
            ['code' => 'connection_not_ready', 'retryable' => true, 'internal_code' => 'session_not_working'],
            $mapper->forConnection('session_not_working', false),
        );
    }

    public function test_connection_errors_keep_v2_engine_codes(): void
    {
        $mapper = new ApplicationErrorMapper;

        $this->assertSame(['code' => 'capacity_exceeded', 'retryable' => false], $mapper->forConnection('capacity_exceeded', false));
        $this->assertSame(['code' => 'idempotency_conflict', 'retryable' => false], $mapper->forConnection('idempotency_conflict', false));
        $this->assertSame(ApplicationErrorCode::ConnectionNotReady, $mapper->map('operation_pending'));
    }
}
