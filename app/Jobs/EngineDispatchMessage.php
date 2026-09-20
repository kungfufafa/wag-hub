<?php

namespace App\Jobs;

use App\Services\Engine\EngineTransportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class EngineDispatchMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $messageId) {}

    public function handle(EngineTransportService $transport): void
    {
        Cache::put('engine:transport-heartbeat', now()->timestamp, 120);
        $transport->dispatch($this->messageId);
    }
}
