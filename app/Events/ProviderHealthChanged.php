<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class ProviderHealthChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $providerAccountId,
        public string $providerName,
        public string $providerSlug,
        public string $providerDriver,
        public string $providerUuid,
        public string $fromStatus,
        public string $toStatus,
        public int $consecutiveFailures,
        public ?string $circuitOpenUntilIso,
        public ?string $errorSummary,
    ) {}
}
