<?php

namespace Tests\Unit\Infrastructure\WhatsApp;

use App\Infrastructure\WhatsApp\InboxPayload;
use PHPUnit\Framework\TestCase;

class InboxPayloadTest extends TestCase
{
    public function test_it_reads_nested_waha_from_me_and_conversation_body(): void
    {
        $event = InboxPayload::eventFromWahaRow([
            'id' => [
                'fromMe' => false,
                'remote' => '6281234567890@c.us',
                'id' => 'ABC123',
            ],
            'key' => [
                'fromMe' => false,
                'remoteJid' => '6281234567890@c.us',
            ],
            'message' => [
                'conversation' => 'Halo dari pelanggan',
            ],
            'messageTimestamp' => 1_700_000_000,
        ]);

        $this->assertNotNull($event);
        $this->assertFalse($event->fromMe);
        $this->assertSame('Halo dari pelanggan', $event->body);
        $this->assertSame('6281234567890@c.us', $event->chatId);
        $this->assertSame('ABC123', $event->messageId);
    }

    public function test_it_treats_outgoing_waha_messages_as_from_me(): void
    {
        $event = InboxPayload::eventFromWahaRow([
            'id' => 'true_6281234567890@c.us_OUT1',
            'fromMe' => true,
            'to' => '6281234567890@c.us',
            'body' => 'Balasan Hub',
            'timestamp' => 1_700_000_060,
        ]);

        $this->assertNotNull($event);
        $this->assertTrue($event->fromMe);
        $this->assertSame('6281234567890@c.us', $event->chatId);
        $this->assertSame('true_6281234567890@c.us_OUT1', $event->messageId);
    }

    public function test_peer_key_unifies_waha_and_gowa_jids(): void
    {
        $this->assertSame('6281234567890', InboxPayload::peerKey('6281234567890@c.us'));
        $this->assertSame('6281234567890', InboxPayload::peerKey('6281234567890@s.whatsapp.net'));
        $this->assertSame('120363@g.us', InboxPayload::peerKey('120363@g.us'));
    }
}
