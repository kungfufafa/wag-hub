<?php

namespace App\Services;

use App\Jobs\DispatchGatewayMessage;
use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use Throwable;

final class GatewayMessageEnqueuer
{
    private const TERMINAL_STATUSES = [
        'provider_accepted',
        'failed',
        'dead_letter',
        'outcome_unknown',
        'expired',
    ];

    public function enqueue(GatewayMessage $message, string $source): bool
    {
        $recovering = $this->hasUnrecoveredFailure($message);

        try {
            if ($this->usesAsyncDispatch()) {
                DispatchGatewayMessage::dispatch($message->getKey());
            } else {
                DispatchGatewayMessage::dispatchSync($message->getKey());
            }
        } catch (Throwable) {
            if ($this->usesAsyncDispatch()) {
                if (! $recovering) {
                    $this->recordEvent($message, 'enqueue_failed', $source);
                }

                return false;
            }

            $message->refresh();
            $status = (string) $message->status;

            // Job never started — treat as handoff failure.
            if ($status === 'queued') {
                if (! $recovering) {
                    $this->recordEvent($message, 'enqueue_failed', $source);
                }

                return false;
            }

            // Job reached the dispatcher; caller should inspect the message status.
            if (in_array($status, self::TERMINAL_STATUSES, true)) {
                if ($recovering) {
                    $this->recordEvent($message, 'enqueue_recovered', $source);
                }

                return true;
            }

            return false;
        }

        if (! $this->usesAsyncDispatch()) {
            $message->refresh();

            // Overlap skip / no-op left the message queued — no worker will pick it up.
            if ((string) $message->status === 'queued') {
                if (! $recovering) {
                    $this->recordEvent($message, 'enqueue_failed', $source);
                }

                return false;
            }
        }

        if ($recovering) {
            $this->recordEvent($message, 'enqueue_recovered', $source);
        }

        return true;
    }

    public function usesAsyncDispatch(): bool
    {
        return (string) config('gateway.dispatch', 'async') === 'async';
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
