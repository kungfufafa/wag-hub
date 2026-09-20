<?php

namespace App\Console\Commands;

use App\Jobs\EngineDeliverWebhook;
use App\Jobs\EngineDispatchMessage;
use App\Jobs\EngineReconcileMessage;
use App\Jobs\EngineWorkerHeartbeat;
use App\Models\EngineEvent;
use App\Models\EngineIntegration;
use App\Models\EngineMedia;
use App\Models\EngineMessage;
use App\Services\Engine\EngineEventService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EngineTransportTick extends Command
{
    protected $signature = 'engine:transport-tick {--limit=50} {--cleanup}';

    protected $description = 'Recover durable sends, ingest runner events, reconcile outcomes, and schedule webhook delivery.';

    public function handle(EngineEventService $events): int
    {
        EngineWorkerHeartbeat::dispatch('transport')->onConnection(config('engine_v2.queue_connection'))->onQueue('engine');
        $limit = min(100, max(1, (int) $this->option('limit')));
        try {
            $events->pollRunner($limit);
        } catch (Throwable) {
            $this->warn('Runner event ingestion is unavailable; durable events remain pending.');
        }
        EngineMessage::query()->where('transport_status', 'queued')->orderBy('id')->limit($limit)->get()
            ->each(fn (EngineMessage $message) => EngineDispatchMessage::dispatch($message->id)->onConnection(config('engine_v2.queue_connection', 'database'))->onQueue('engine'));
        EngineMessage::query()->whereIn('transport_status', ['processing', 'outcome_unknown'])
            ->where('processing_at', '<', now()->subMinutes(2))
            ->where(fn ($query) => $query->whereNull('reconciled_at')->orWhere('reconciled_at', '<', now()->subMinutes(1)))
            ->orderBy('reconciled_at')->limit($limit)->get()
            ->each(fn (EngineMessage $message) => EngineReconcileMessage::dispatch($message->id)->onConnection(config('engine_v2.queue_connection', 'database'))->onQueue('engine'));
        EngineEvent::query()->whereNull('delivered_at')->whereNull('failed_at')
            ->where(fn (Builder $query) => $query->where('created_at', '<=', now()->subHours((int) config('engine_v2.webhook_retry_hours', 24)))
                ->orWhere('expires_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('delivery_locked_until')->orWhere('delivery_locked_until', '<=', now()))
            ->update(['failed_at' => now(), 'delivery_error' => 'retry_window_exhausted', 'next_attempt_at' => null]);
        // Suspended installations retain replayable events without occupying another installation's batch.
        $enabled = EngineIntegration::query()->where('webhook_enabled', true)
            ->whereNotNull('webhook_url')->where('webhook_url', '!=', '')->whereNotNull('webhook_secret')
            ->whereHas('clientApplication', fn (Builder $query) => $query->where('is_active', true))
            ->select('client_application_id');
        EngineEvent::query()->whereIn('client_application_id', $enabled)->whereNull('delivered_at')->whereNull('failed_at')
            ->where('next_attempt_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('delivery_locked_until')->orWhere('delivery_locked_until', '<=', now()))
            ->orderBy('id')->limit($limit)->get()
            ->each(fn (EngineEvent $event) => EngineDeliverWebhook::dispatch($event->id)->onConnection(config('engine_v2.queue_connection', 'database'))->onQueue('engine'));
        if ($this->option('cleanup')) {
            EngineEvent::query()->where('expires_at', '<=', now())->delete();
            EngineMedia::query()->where('expires_at', '<=', now())->chunkById(100, function ($items): void {
                foreach ($items as $media) {
                    if (Storage::disk($media->disk)->delete($media->path)) {
                        $media->delete();
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
