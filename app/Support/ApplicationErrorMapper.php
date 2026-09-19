<?php

namespace App\Support;

/**
 * Maps internal gateway error codes to application-facing action codes.
 * Preserves the original code for backward compatibility.
 */
final class ApplicationErrorMapper
{
    public static function actionFor(string $internalCode): string
    {
        return match ($internalCode) {
            'unauthenticated' => 'authentication_failed',
            'route_unavailable', 'engine_provider_unavailable', 'connection_not_ready' => 'connection_not_ready',
            'providers_failed', 'inbox_provider_rejected', 'inbox_provider_unavailable' => 'delivery_failed',
            'provider_outcome_unknown', 'delivery_unknown' => 'delivery_outcome_unknown',
            'message_expired' => 'message_expired',
            'idempotency_conflict' => 'idempotency_conflict',
            'invalid_phone', 'recipient_invalid' => 'recipient_invalid',
            'capability_not_supported', 'attachment_format_unsupported', 'attachment_size_unsupported' => 'capability_not_supported',
            'queue_unavailable' => 'rate_limited',
            default => $internalCode,
        };
    }

    /**
     * @return array{code: string, action: string, retryable: bool}
     */
    public static function payload(string $internalCode, bool $retryable = false): array
    {
        return [
            'code' => $internalCode,
            'action' => self::actionFor($internalCode),
            'retryable' => $retryable,
        ];
    }
}
