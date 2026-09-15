<?php

namespace App\Services;

use App\Models\GatewayMessage;
use App\Models\MessageAttempt;
use App\Models\MessageEvent;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StaleGatewayMessageRecovery
{
    public function __construct(
        private readonly GatewayMessageEnqueuer $enqueuer,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * @return array{examined: int, requeued: int, enqueue_failed: int, accepted: int, outcome_unknown: int, failed: int}
     */
    public function recover(int $minutes, int $limit): array
    {
        $cutoff = now()->subMinutes($minutes);
        $processingMessageIds = GatewayMessage::query()
            ->whereIn('mode', ['async', 'sync'])
            ->where('status', 'processing')
            ->whereNotNull('processing_at')
            ->where('processing_at', '<=', $cutoff)
            ->orderBy('processing_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $remaining = max(0, $limit - $processingMessageIds->count());
        $failedEnqueueMessageIds = $this->failedEnqueueMessageIds($remaining);
        $summary = [
            'examined' => 0,
            'requeued' => 0,
            'enqueue_failed' => 0,
            'accepted' => 0,
            'outcome_unknown' => 0,
            'failed' => 0,
        ];

        foreach ($processingMessageIds as $messageId) {
            $decision = DB::transaction(
                fn (): ?array => $this->recoverOne((int) $messageId, $cutoff),
            );

            if ($decision === null) {
                continue;
            }

            $outcome = $decision['outcome'];

            if ($decision['enqueue']) {
                $message = GatewayMessage::query()->find($decision['message_id']);
                $outcome = $message !== null && $this->enqueuer->enqueue($message, 'system')
                    ? 'requeued'
                    : 'enqueue_failed';
            }

            $this->incrementSummary($summary, $outcome);
        }

        foreach ($failedEnqueueMessageIds as $messageId) {
            $message = GatewayMessage::query()->find($messageId);

            if ($message === null || ! $this->enqueuer->hasUnrecoveredFailure($message)) {
                continue;
            }

            $outcome = $this->enqueuer->enqueue($message, 'system')
                ? 'requeued'
                : 'enqueue_failed';
            $this->incrementSummary($summary, $outcome);
        }

        return $summary;
    }

    /**
     * @return array{outcome: string, enqueue: bool, message_id: int}|null
     */
    private function recoverOne(int $messageId, Carbon $cutoff): ?array
    {
        /** @var GatewayMessage|null $message */
        $message = GatewayMessage::query()
            ->lockForUpdate()
            ->find($messageId);

        if (! $this->isStillRecoverable($message, $cutoff)) {
            return null;
        }

        /** @var MessageAttempt|null $attempt */
        $attempt = MessageAttempt::query()
            ->where('gateway_message_id', $message->id)
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($attempt === null) {
            if ($message->mode === 'sync') {
                return $this->decision(
                    $this->failSyncWithoutAttempt($message),
                    $message,
                );
            }

            return $this->decision(
                $this->requeueWithoutAttempt($message),
                $message,
                enqueue: true,
            );
        }

        if ($attempt->status === 'started') {
            return $this->decision(
                $this->reconcileStartedAttempt($message, $attempt),
                $message,
            );
        }

        $outcome = match ((string) $attempt->status) {
            'accepted' => $this->restoreAcceptedAggregate($message, $attempt),
            'outcome_unknown' => $this->restoreUnknownAggregate($message, $attempt),
            'rejected', 'provider_failed' => $this->restoreFailedAggregate($message, $attempt),
            default => $this->restoreUnknownAggregate($message, $attempt),
        };

        return $this->decision($outcome, $message);
    }

    private function isStillRecoverable(?GatewayMessage $message, Carbon $cutoff): bool
    {
        return $message !== null
            && in_array($message->mode, ['async', 'sync'], true)
            && $message->status === 'processing'
            && $message->processing_at !== null
            && $message->processing_at->lessThanOrEqualTo($cutoff);
    }

    private function requeueWithoutAttempt(GatewayMessage $message): string
    {
        $message->forceFill([
            'status' => 'queued',
            'queued_at' => now(),
            'processing_at' => null,
        ])->save();

        $this->appendEvent($message, 'recovery_requeued', null);

        return 'requeued';
    }

    private function failSyncWithoutAttempt(GatewayMessage $message): string
    {
        $message->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'last_error_code' => 'stale_sync_without_attempt',
            'last_error_message' => 'The synchronous request stopped before a provider attempt began and is safe for an explicit retry.',
        ])->save();

        $this->appendEvent($message, 'recovery_sync_failed_before_attempt', null);
        $this->finalizeAttachment($message);

        return 'failed';
    }

    private function reconcileStartedAttempt(
        GatewayMessage $message,
        MessageAttempt $attempt,
    ): string {
        $attempt->forceFill([
            'status' => 'outcome_unknown',
            'delivery_certainty' => 'unknown',
            'retry_disposition' => 'reconcile_only',
            'error_code' => 'stale_started_attempt',
            'error_message' => 'Worker stopped after the provider attempt began; delivery outcome requires reconciliation.',
            'finished_at' => now(),
        ])->save();

        return $this->restoreUnknownAggregate($message, $attempt);
    }

    private function restoreAcceptedAggregate(
        GatewayMessage $message,
        MessageAttempt $attempt,
    ): string {
        $message->forceFill([
            'status' => 'provider_accepted',
            'accepted_provider_account_id' => $attempt->provider_account_id,
            'provider_message_id' => $attempt->provider_message_id,
            'provider_accepted_at' => $attempt->finished_at ?? now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $this->appendEvent($message, 'recovery_provider_accepted', $attempt);
        $this->finalizeAttachment($message);

        return 'accepted';
    }

    private function restoreUnknownAggregate(
        GatewayMessage $message,
        MessageAttempt $attempt,
    ): string {
        $message->forceFill([
            'status' => 'outcome_unknown',
            'outcome_unknown_at' => now(),
            'last_error_code' => $attempt->error_code ?: 'recovered_outcome_unknown',
            'last_error_message' => $attempt->error_message
                ?: 'The completed attempt does not provide safe evidence for another send.',
        ])->save();

        $this->appendEvent($message, 'recovery_outcome_unknown', $attempt);
        $this->finalizeAttachment($message);

        return 'outcome_unknown';
    }

    private function restoreFailedAggregate(
        GatewayMessage $message,
        MessageAttempt $attempt,
    ): string {
        $message->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'last_error_code' => $attempt->error_code ?: 'recovered_provider_failure',
            'last_error_message' => $attempt->error_message
                ?: 'The completed provider attempt failed before aggregate state was saved.',
        ])->save();

        $this->appendEvent($message, 'recovery_failed', $attempt);
        $this->finalizeAttachment($message);

        return 'failed';
    }

    private function appendEvent(
        GatewayMessage $message,
        string $type,
        ?MessageAttempt $attempt,
    ): void {
        MessageEvent::forceCreate([
            'gateway_message_id' => $message->id,
            'type' => $type,
            'source' => 'system',
            'data' => $attempt === null ? null : [
                'attempt_id' => $attempt->id,
                'attempt_sequence' => $attempt->sequence,
                'attempt_status' => $attempt->status,
            ],
            'occurred_at' => now(),
        ]);
    }

    private function finalizeAttachment(GatewayMessage $message): void
    {
        $attachmentId = $message->outboundAttachment()?->attachmentId;

        if ($attachmentId === null) {
            return;
        }

        try {
            $this->attachments->finalizeReference($attachmentId);
        } catch (\Throwable) {
            // A missing file is retained as a ledger error; recovery outcome
            // must not be rolled back because metadata cleanup failed.
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function failedEnqueueMessageIds(int $limit): Collection
    {
        if ($limit === 0) {
            return collect();
        }

        return GatewayMessage::query()
            ->where('status', 'queued')
            ->whereNotNull('queued_at')
            ->whereExists(function (Builder $failed): void {
                $failed->selectRaw('1')
                    ->from('message_events as failed_queue_events')
                    ->whereColumn(
                        'failed_queue_events.gateway_message_id',
                        'gateway_messages.id',
                    )
                    ->where('failed_queue_events.type', 'enqueue_failed')
                    ->whereColumn(
                        'failed_queue_events.occurred_at',
                        '>=',
                        'gateway_messages.queued_at',
                    )
                    ->whereNotExists(function (Builder $recovered): void {
                        $recovered->selectRaw('1')
                            ->from('message_events as recovered_queue_events')
                            ->whereColumn(
                                'recovered_queue_events.gateway_message_id',
                                'failed_queue_events.gateway_message_id',
                            )
                            ->where('recovered_queue_events.type', 'enqueue_recovered')
                            ->whereColumn(
                                'recovered_queue_events.id',
                                '>',
                                'failed_queue_events.id',
                            );
                    });
            })
            ->orderBy('queued_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
    }

    /**
     * @return array{outcome: string, enqueue: bool, message_id: int}
     */
    private function decision(
        string $outcome,
        GatewayMessage $message,
        bool $enqueue = false,
    ): array {
        return [
            'outcome' => $outcome,
            'enqueue' => $enqueue,
            'message_id' => (int) $message->getKey(),
        ];
    }

    /**
     * @param  array{examined: int, requeued: int, enqueue_failed: int, accepted: int, outcome_unknown: int, failed: int}  $summary
     */
    private function incrementSummary(array &$summary, string $outcome): void
    {
        $summary['examined']++;
        $summary[$outcome]++;
    }
}
