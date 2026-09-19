<?php

namespace App\Services\Connections;

use App\Domain\Connections\ApplicationErrorCode;

final class ApplicationErrorMapper
{
    public function map(string $internalCode): ApplicationErrorCode
    {
        return match ($internalCode) {
            'unauthenticated', 'forbidden' => ApplicationErrorCode::AuthenticationFailed,
            'rate_limited' => ApplicationErrorCode::RateLimited,
            'invalid_phone', 'recipient_invalid' => ApplicationErrorCode::RecipientInvalid,
            'connection_not_found', 'not_found' => ApplicationErrorCode::ConnectionNotFound,
            'not_connected', 'route_unavailable', 'engine_unavailable',
            'engine_provider_unavailable', 'connection_not_ready' => ApplicationErrorCode::ConnectionNotReady,
            'attachment_format_unsupported', 'capability_not_supported',
            'provider_driver_unsupported' => ApplicationErrorCode::CapabilityNotSupported,
            'message_expired' => ApplicationErrorCode::MessageExpired,
            'provider_outcome_unknown', 'delivery_unknown', 'outcome_unknown' => ApplicationErrorCode::DeliveryOutcomeUnknown,
            default => ApplicationErrorCode::DeliveryFailed,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function applicationError(
        string $internalCode,
        bool $retryable,
        ?string $auditId = null,
        bool $connectionOriented = false,
    ): array {
        $action = $this->map($internalCode);
        $error = [
            'code' => $connectionOriented ? $action->value : $internalCode,
            'retryable' => $retryable,
        ];

        if ($auditId !== null && $auditId !== '') {
            $error['audit_id'] = $auditId;
        }

        if ($connectionOriented) {
            $error['internal_code'] = $internalCode;
        } else {
            $error['action'] = $action->value;
        }

        return $error;
    }
}
