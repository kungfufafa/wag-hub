<?php

namespace App\Jobs;

use App\Models\EngineSession;
use App\Services\Engine\SessionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EngineRefreshSession implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $sessionId) {}

    public function uniqueId(): string
    {
        return (string) $this->sessionId;
    }

    public function handle(SessionService $sessions): void
    {
        $session = EngineSession::query()->find($this->sessionId);
        if ($session && $session->pending_operation_id === null
            && (! $session->observed_at || $session->observed_at->lt(now()->subSeconds(15)))) {
            $sessions->refresh($session);
        }
    }
}
