<?php

namespace App\Services\Connections;

use App\Domain\Connections\ApplicationErrorCode;

final class ApplicationErrorMapper
{
    /**
     * Internal failures that all mean "this connection cannot send right now, try again later".
     */
    private const NOT_READY = [
        'not_connected', 'route_unavailable', 'engine_unavailable', 'engine_unconfigured', 'engine_unreachable',
        'engine_provider_unavailable', 'operation_pending', 'operation_incomplete', 'session_not_working',
    ];

    public function map(string $internalCode): ApplicationErrorCode
    {
        if ($internalCode === 'connection_not_ready' || in_array($internalCode, self::NOT_READY, true)) {
            return ApplicationErrorCode::ConnectionNotReady;
        }

        return match ($internalCode) {
            'unauthenticated', 'forbidden' => ApplicationErrorCode::AuthenticationFailed,
            'rate_limited' => ApplicationErrorCode::RateLimited,
            'invalid_phone', 'recipient_invalid' => ApplicationErrorCode::RecipientInvalid,
            'connection_not_found', 'not_found' => ApplicationErrorCode::ConnectionNotFound,
            'attachment_format_unsupported', 'capability_not_supported',
            'provider_driver_unsupported' => ApplicationErrorCode::CapabilityNotSupported,
            'message_expired' => ApplicationErrorCode::MessageExpired,
            'provider_outcome_unknown', 'delivery_unknown', 'outcome_unknown' => ApplicationErrorCode::DeliveryOutcomeUnknown,
            default => ApplicationErrorCode::DeliveryFailed,
        };
    }

    /**
     * The v2 error object for the Connection API. Engine and route outages become a retryable
     * connection_not_ready (the original code stays in internal_code); every other code, such as
     * capacity_exceeded or idempotency_conflict, is reported exactly as /api/v2/sessions does.
     *
     * @return array{code: string, retryable: bool, internal_code?: string}
     */
    public function forConnection(string $internalCode, bool $retryable): array
    {
        if (! in_array($internalCode, self::NOT_READY, true)) {
            return ['code' => $internalCode, 'retryable' => $retryable];
        }

        return [
            'code' => ApplicationErrorCode::ConnectionNotReady->value,
            'retryable' => true,
            'internal_code' => $internalCode,
        ];
    }
}
