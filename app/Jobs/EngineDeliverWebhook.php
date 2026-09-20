<?php

namespace App\Jobs;

use App\Services\Engine\EngineEventService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class EngineDeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $eventId) {}

    public function handle(EngineEventService $events): void
    {
        Cache::put('engine:transport-heartbeat', now()->timestamp, 120);
        $events->deliver($this->eventId);
    }
}
