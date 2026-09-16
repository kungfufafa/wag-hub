<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast (via Reverb) whenever an inbox message is recorded, so the Inbox
 * updates in real time instead of waiting for the poll. Broadcast synchronously
 * (Now) to minimise latency.
 */
class InboxMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $providerAccountId,
        public string $chatId,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        // Public channel — no sensitive payload, only a nudge to refresh.
        return [new Channel('inbox')];
    }

    public function broadcastAs(): string
    {
        return 'InboxMessageReceived';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'provider_account_id' => $this->providerAccountId,
            'chat_id' => $this->chatId,
        ];
    }
}
