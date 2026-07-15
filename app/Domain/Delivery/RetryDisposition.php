<?php

namespace App\Domain\Delivery;

enum RetryDisposition: string
{
    case FallbackAllowed = 'fallback_allowed';
    case DoNotRetry = 'do_not_retry';
    case ReconcileOnly = 'reconcile_only';
}
