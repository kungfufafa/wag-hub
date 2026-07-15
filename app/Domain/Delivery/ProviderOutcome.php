<?php

namespace App\Domain\Delivery;

enum ProviderOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case ProviderFailed = 'provider_failed';
    case OutcomeUnknown = 'outcome_unknown';
}
