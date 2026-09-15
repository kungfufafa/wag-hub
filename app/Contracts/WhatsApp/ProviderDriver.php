<?php

namespace App\Contracts\WhatsApp;

use App\Domain\Delivery\OutboundMessage;
use App\Domain\Delivery\ProviderResult;
use App\Models\ProviderAccount;

interface ProviderDriver
{
    /**
     * Deliver a text message or an attachment (with optional caption) through
     * the provider. Implementations must handle every AttachmentKind.
     */
    public function send(ProviderAccount $account, OutboundMessage $message): ProviderResult;
}
