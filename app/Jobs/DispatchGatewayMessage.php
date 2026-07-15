<?php

namespace App\Jobs;

use App\Models\GatewayMessage;
use App\Services\GatewayMessageDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class DispatchGatewayMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $messageId) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("gateway-message:{$this->messageId}"))->dontRelease()];
    }

    public function handle(GatewayMessageDispatcher $dispatcher): void
    {
        $message = GatewayMessage::query()->find($this->messageId);

        if (! $message) {
            return;
        }

        $dispatcher->dispatch($message);
    }
}
