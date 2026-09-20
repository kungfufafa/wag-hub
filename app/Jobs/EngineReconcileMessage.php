<?php

namespace App\Jobs;

use App\Models\EngineMessage;
use App\Services\Engine\EngineTransportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class EngineReconcileMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $messageId) {}

    public function handle(EngineTransportService $transport): void
    {
        Cache::put('engine:transport-heartbeat', now()->timestamp, 120);
        $message = EngineMessage::query()->find($this->messageId);
        if ($message !== null && in_array($message->transport_status, ['processing', 'outcome_unknown'], true)) {
            $transport->reconcile($message);
        }
    }
}
