<?php

namespace App\Console\Commands;

use App\Jobs\EngineRefreshSession;
use App\Jobs\EngineWorkerHeartbeat;
use App\Jobs\ProcessEngineOperation;
use App\Models\EngineOperation;
use App\Models\EngineSession;
use App\Services\Engine\SessionService;
use Illuminate\Console\Command;

class EngineControlTick extends Command
{
    protected $signature = 'engine:control-tick {--limit=100}';

    protected $description = 'Recover durable session commands, refresh observations, and remove abandoned connections.';

    public function handle(SessionService $sessions): int
    {
        EngineWorkerHeartbeat::dispatch('control')->onConnection(config('engine_v2.queue_connection'))->onQueue('engine-control');
        $limit = min(500, max(1, (int) $this->option('limit')));
        EngineOperation::query()->whereIn('status', ['pending', 'processing'])
            ->where('available_at', '<=', now())
            ->where(fn ($query) => $query->where('status', 'pending')->orWhere('updated_at', '<', now()->subSeconds(60)))
            ->orderBy('id')->limit($limit)->get()->each(function (EngineOperation $operation): void {
                ProcessEngineOperation::dispatch($operation->id)->onConnection(config('engine_v2.queue_connection'))->onQueue('engine-control');
            });
        EngineSession::query()->whereNull('authenticated_at')
            ->where('provisioning_expires_at', '<=', now())
            ->limit($limit)->get()->each(fn (EngineSession $session) => $sessions->command($session, 'delete'));
        EngineSession::query()->where('generation', '>', 0)->whereNull('pending_operation_id')
            ->where(fn ($query) => $query->whereNull('observed_at')->orWhere('observed_at', '<', now()->subSeconds(15)))
            ->orderBy('observed_at')->limit($limit)->get()->each(fn (EngineSession $session) => EngineRefreshSession::dispatch($session->id)->onConnection(config('engine_v2.queue_connection'))->onQueue('engine-control'));

        return self::SUCCESS;
    }
}
