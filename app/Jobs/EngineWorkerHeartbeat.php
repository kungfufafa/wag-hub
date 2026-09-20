<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class EngineWorkerHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public int $tries = 1;

    public function __construct(public string $worker) {}

    public function uniqueId(): string
    {
        return $this->worker;
    }

    public function handle(): void
    {
        Cache::put('engine:'.$this->worker.'-heartbeat', now()->timestamp, 120);
    }
}
