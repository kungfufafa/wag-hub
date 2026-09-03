<?php

namespace Tests\Unit\Infrastructure\WhatsApp;

use App\Infrastructure\WhatsApp\InboxWebhookParser;
use PHPUnit\Framework\TestCase;

class InboxWebhookParserTest extends TestCase
{
    public function test_it_ignores_waha_ack_events(): void
    {
        $parser = new InboxWebhookParser;

        $this->assertSame([], $parser->parse('waha', [
            'event' => 'message.ack',
            'payload' => [
                'id' => 'ack-1',
                'from' => '628123@c.us',
                'fromMe' => false,
                'ack' => 3,
            ],
        ]));
    }

    public function test_it_parses_gowa_nested_message_text(): void
    {
        $parser = new InboxWebhookParser;
        $events = $parser->parse('gowa', [
            'chat_id' => '628777@s.whatsapp.net',
            'from' => '628777@s.whatsapp.net',
            'pushname' => 'Sari',
            'message' => [
                'id' => 'gowa-1',
                'text' => 'Halo GOWA',
            ],
        ]);

        $this->assertCount(1, $events);
        $this->assertFalse($events[0]->fromMe);
        $this->assertSame('Halo GOWA', $events[0]->body);
        $this->assertSame('gowa-1', $events[0]->messageId);
    }
}
