<?php

namespace App\Contracts\WhatsApp;

use App\Domain\NumberCheck\NumberCheckResult;
use App\Models\ProviderAccount;

interface ProviderNumberChecker
{
    public function checkNumber(ProviderAccount $account, string $recipient): NumberCheckResult;
}
