<?php

namespace App\Jobs;

use App\Models\EngineOperation;
use App\Services\Engine\SessionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

class ProcessEngineOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $operationId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('engine-operation:'.$this->operationId))->dontRelease()->expireAfter(60)];
    }

    public function handle(SessionService $sessions): void
    {
        Cache::put('engine:control-heartbeat', now()->timestamp, 120);
        $operation = EngineOperation::query()->find($this->operationId);
        if ($operation && (! $operation->available_at || $operation->available_at->isPast())) {
            $sessions->execute($operation);
        }
    }
}
