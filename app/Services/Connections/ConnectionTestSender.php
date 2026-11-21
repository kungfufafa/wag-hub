<?php

namespace App\Services\Connections;

use App\Models\WhatsAppConnection;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Str;

/**
 * Admin test send: the same path as POST /api/v2/connections/{id}/messages.
 */
final class ConnectionTestSender
{
    public function __construct(private ConnectionMessenger $messenger, private PhoneNormalizer $phones) {}

    public function send(WhatsAppConnection $connection, string $recipient, string $text): ConnectionDelivery
    {
        return $this->messenger->send(
            $connection,
            ['to' => $this->phones->normalize($recipient), 'type' => 'text', 'text' => $text],
            'conn-test-'.Str::uuid(),
        );
    }
}
