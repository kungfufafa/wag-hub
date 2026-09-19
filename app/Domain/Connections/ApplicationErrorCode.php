<?php

namespace App\Domain\Connections;

enum ApplicationErrorCode: string
{
    case ConnectionNotReady = 'connection_not_ready';
    case AuthenticationFailed = 'authentication_failed';
    case RecipientInvalid = 'recipient_invalid';
    case CapabilityNotSupported = 'capability_not_supported';
    case MessageExpired = 'message_expired';
    case RateLimited = 'rate_limited';
    case DeliveryFailed = 'delivery_failed';
    case DeliveryOutcomeUnknown = 'delivery_outcome_unknown';
}
