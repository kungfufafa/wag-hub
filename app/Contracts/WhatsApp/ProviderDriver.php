<?php

namespace App\Contracts\WhatsApp;

use App\Domain\Delivery\OutboundText;
use App\Domain\Delivery\ProviderResult;
use App\Models\ProviderAccount;

interface ProviderDriver
{
    public function send(ProviderAccount $account, OutboundText $message): ProviderResult;
}
