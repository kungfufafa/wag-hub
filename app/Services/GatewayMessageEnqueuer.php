<?php

namespace App\Services;

use App\Jobs\DispatchGatewayMessage;
use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use Throwable;

final class GatewayMessageEnqueuer
{
    public function enqueue(GatewayMessage $message, string $source): bool
    {
        $recovering = $this->hasUnrecoveredFailure($message);

        try {
            DispatchGatewayMessage::dispatch($message->getKey());
        } catch (Throwable) {
            if (! $recovering) {
                $this->recordEvent($message, 'enqueue_failed', $source);
            }

            return false;
        }

        if ($recovering) {
            $this->recordEvent($message, 'enqueue_recovered', $source);
        }

        return true;
    }

    public function hasUnrecoveredFailure(GatewayMessage $message): bool
    {
        if ((string) $message->status !== 'queued') {
            return false;
        }

        $latestQueueEvent = MessageEvent::query()
            ->where('gateway_message_id', $message->getKey())
            ->whereIn('type', ['enqueue_failed', 'enqueue_recovered'])
            ->when(
                $message->queued_at !== null,
                fn ($query) => $query->where('occurred_at', '>=', $message->queued_at),
            )
            ->latest('id')
            ->value('type');

        return $latestQueueEvent === 'enqueue_failed';
    }

    private function recordEvent(
        GatewayMessage $message,
        string $type,
        string $source,
    ): void {
        $event = new MessageEvent;
        $event->forceFill([
            'gateway_message_id' => $message->getKey(),
            'type' => $type,
            'source' => $source,
            'data' => null,
            'occurred_at' => now(),
        ]);
        $event->save();
    }
}
