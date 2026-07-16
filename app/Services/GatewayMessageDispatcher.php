<?php

namespace App\Services;

use App\Domain\Delivery\OutboundText;
use App\Domain\Delivery\ProviderOutcome;
use App\Domain\Delivery\ProviderResult;
use App\Infrastructure\WhatsApp\ProviderDriverManager;
use App\Models\GatewayMessage;
use App\Models\MessageAttempt;
use App\Models\ProviderAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class GatewayMessageDispatcher
{
    private const TERMINAL_STATUSES = [
        'provider_accepted',
        'failed',
        'outcome_unknown',
        'expired',
        'dead_letter',
    ];

    public function __construct(private ProviderDriverManager $drivers) {}

    public function dispatch(GatewayMessage $message): GatewayMessage
    {
        $message = $message->fresh() ?? $message;

        if (in_array((string) $message->status, self::TERMINAL_STATUSES, true)) {
            return $message;
        }

        if ((string) $message->status === 'processing' && (string) $message->mode === 'async') {
            return $message;
        }

        if (! in_array((string) $message->status, ['queued', 'processing'], true)) {
            return $message;
        }

        if ($this->isExpired($message)) {
            return $this->markExpired($message);
        }

        $message = $this->markProcessing($message);
        $policyId = $this->resolveRoutingPolicyId($message);

        if ($policyId === null) {
            return $this->markFailed(
                $message,
                errorCode: 'route_unavailable',
                errorMessage: 'Tidak ada routing policy aktif untuk pesan ini.',
            );
        }

        if ((int) $message->routing_policy_id !== $policyId) {
            $message->forceFill(['routing_policy_id' => $policyId])->save();
        }

        $steps = DB::table('routing_steps')
            ->where('routing_policy_id', $policyId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($steps->isEmpty()) {
            return $this->markFailed(
                $message,
                errorCode: 'route_unavailable',
                errorMessage: 'Routing policy tidak memiliki provider aktif.',
            );
        }

        $lastResult = null;
        $hasAttemptedProvider = false;

        foreach ($steps as $index => $step) {
            $message = $message->fresh() ?? $message;

            if ($this->isExpired($message)) {
                return $this->markExpired($message);
            }

            $provider = ProviderAccount::query()->find($step->provider_account_id);

            if (! $provider || ! (bool) $step->is_active || ! $this->providerIsUsable($provider)) {
                if ($provider) {
                    $this->recordSkippedAttempt($message, $provider, 'provider_unavailable');
                }

                continue;
            }

            $hasAttemptedProvider = true;
            $attemptId = $this->startAttempt($message, $provider);
            $startedAt = hrtime(true);
            $result = $this->send($provider, $message);
            $latencyMs = max(0, (int) floor((hrtime(true) - $startedAt) / 1_000_000));

            $this->finishAttempt($attemptId, $result, $latencyMs);
            $this->recordProviderOutcome($provider, $result);
            $lastResult = $result;

            if ($result->outcome === ProviderOutcome::Accepted) {
                return $this->markAccepted($message, $provider, $result);
            }

            if ($result->outcome === ProviderOutcome::OutcomeUnknown) {
                return $this->markOutcomeUnknown($message, $result);
            }

            if (! $result->allowsFallback()) {
                return $this->markFailed(
                    $message,
                    errorCode: $result->errorCode ?? 'message_rejected',
                    errorMessage: $result->errorMessage ?? 'Provider menolak pesan.',
                );
            }

            if ($this->isExpired($message->fresh() ?? $message)) {
                return $this->markExpired($message);
            }

            if ($index < $steps->count() - 1) {
                $this->appendEvent($message->id, 'fallback_started');
            }
        }

        if (! $hasAttemptedProvider) {
            return $this->markFailed(
                $message,
                errorCode: 'route_unavailable',
                errorMessage: 'Tidak ada provider yang dapat digunakan.',
            );
        }

        return $this->markFailed(
            $message,
            errorCode: $lastResult?->errorCode ?? 'providers_failed',
            errorMessage: $lastResult?->errorMessage ?? 'Semua provider gagal menerima pesan.',
        );
    }

    private function markProcessing(GatewayMessage $message): GatewayMessage
    {
        DB::transaction(function () use ($message): void {
            $message->forceFill([
                'status' => 'processing',
                'processing_at' => $message->processing_at ?? now(),
            ])->save();

            $this->appendEventOnce($message->id, 'processing');
        });

        return $message->fresh() ?? $message;
    }

    private function resolveRoutingPolicyId(GatewayMessage $message): ?int
    {
        if ($message->routing_policy_id !== null) {
            $existing = DB::table('routing_policies')
                ->where('id', $message->routing_policy_id)
                ->where('operation', 'message')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }
        }

        foreach ([$message->client_application_id, null] as $applicationId) {
            $policy = $this->policyQuery($message, $applicationId)
                ->where('key', $message->route_key)
                ->orderByRaw('CASE WHEN purpose = ? THEN 0 ELSE 1 END', [$message->purpose])
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            if ($policy) {
                return (int) $policy->id;
            }

            $default = $this->policyQuery($message, $applicationId)
                ->where('is_default', true)
                ->orderByRaw('CASE WHEN purpose = ? THEN 0 ELSE 1 END', [$message->purpose])
                ->orderBy('id')
                ->first();

            if ($default) {
                return (int) $default->id;
            }
        }

        return null;
    }

    private function policyQuery(GatewayMessage $message, mixed $applicationId): Builder
    {
        $query = DB::table('routing_policies')
            ->where('operation', 'message')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function (Builder $query) use ($message): void {
                $query->where('purpose', $message->purpose)->orWhereNull('purpose');
            });

        if ($applicationId === null) {
            return $query->whereNull('client_application_id');
        }

        return $query->where('client_application_id', $applicationId);
    }

    private function providerIsUsable(ProviderAccount $provider): bool
    {
        if (! (bool) $provider->is_active) {
            return false;
        }

        if ($provider->circuit_open_until === null) {
            return true;
        }

        return now()->greaterThanOrEqualTo($provider->circuit_open_until);
    }

    private function startAttempt(GatewayMessage $message, ProviderAccount $provider): int
    {
        return DB::transaction(function () use ($message, $provider): int {
            $now = now();
            $sequence = ((int) DB::table('message_attempts')
                ->where('gateway_message_id', $message->id)
                ->max('sequence')) + 1;

            $attempt = new MessageAttempt;
            $attempt->forceFill([
                'gateway_message_id' => $message->id,
                'provider_account_id' => $provider->id,
                'sequence' => $sequence,
                'status' => 'started',
                'delivery_certainty' => 'not_sent',
                'retry_disposition' => 'do_not_retry',
                'http_status' => null,
                'provider_message_id' => null,
                'latency_ms' => null,
                'error_code' => null,
                'error_message' => null,
                'response_excerpt' => null,
                'started_at' => $now,
                'finished_at' => null,
            ])->save();

            $this->appendEvent($message->id, 'attempt_started');

            return (int) $attempt->getKey();
        });
    }

    private function recordSkippedAttempt(
        GatewayMessage $message,
        ProviderAccount $provider,
        string $reason,
    ): void {
        DB::transaction(function () use ($message, $provider, $reason): void {
            $now = now();
            $sequence = ((int) DB::table('message_attempts')
                ->where('gateway_message_id', $message->id)
                ->max('sequence')) + 1;

            $attempt = new MessageAttempt;
            $attempt->forceFill([
                'gateway_message_id' => $message->id,
                'provider_account_id' => $provider->id,
                'sequence' => $sequence,
                'status' => 'skipped',
                'delivery_certainty' => 'not_sent',
                'retry_disposition' => 'fallback_allowed',
                'http_status' => null,
                'provider_message_id' => null,
                'latency_ms' => 0,
                'error_code' => $reason,
                'error_message' => 'Provider dilewati karena tidak aktif atau circuit sedang terbuka.',
                'response_excerpt' => null,
                'started_at' => $now,
                'finished_at' => $now,
            ])->save();

            $this->appendEvent($message->id, 'attempt_skipped');
        });
    }

    private function send(ProviderAccount $provider, GatewayMessage $message): ProviderResult
    {
        try {
            return $this->drivers->send(
                $provider,
                new OutboundText(
                    recipient: (string) $message->recipient,
                    body: (string) $message->body,
                ),
            );
        } catch (Throwable) {
            return ProviderResult::outcomeUnknown(
                errorCode: 'unexpected_driver_failure',
                errorMessage: 'Driver berhenti setelah proses pengiriman dimulai.',
            );
        }
    }

    private function finishAttempt(int $attemptId, ProviderResult $result, int $latencyMs): void
    {
        MessageAttempt::query()->findOrFail($attemptId)->forceFill([
            'status' => $result->outcome->value,
            'delivery_certainty' => $result->deliveryCertainty->value,
            'retry_disposition' => $result->retryDisposition->value,
            'http_status' => $result->httpStatus,
            'provider_message_id' => $result->providerMessageId,
            'latency_ms' => $latencyMs,
            'error_code' => $this->sanitize($result->errorCode, 120),
            'error_message' => $this->sanitize($result->errorMessage, 500),
            'finished_at' => now(),
        ])->save();
    }

    private function recordProviderOutcome(
        ProviderAccount $provider,
        ProviderResult $result,
    ): void {
        if (! in_array($result->outcome, [
            ProviderOutcome::Accepted,
            ProviderOutcome::ProviderFailed,
            ProviderOutcome::OutcomeUnknown,
        ], true)) {
            return;
        }

        DB::transaction(function () use ($provider, $result): void {
            $lockedProvider = ProviderAccount::query()
                ->lockForUpdate()
                ->find($provider->getKey());

            if ($lockedProvider === null) {
                return;
            }

            if ($result->outcome === ProviderOutcome::Accepted) {
                $lockedProvider->forceFill([
                    'consecutive_failures' => 0,
                    'health_status' => 'healthy',
                    'circuit_open_until' => null,
                ])->save();

                return;
            }

            $failureCount = (int) $lockedProvider->consecutive_failures + 1;
            $failureThreshold = max(
                1,
                (int) config('gateway.provider_health.failure_threshold', 3),
            );
            $circuitSeconds = max(
                1,
                (int) config('gateway.provider_health.circuit_open_seconds', 300),
            );

            $lockedProvider->forceFill([
                'consecutive_failures' => $failureCount,
                'health_status' => $failureCount >= $failureThreshold
                    ? 'unavailable'
                    : 'degraded',
                'circuit_open_until' => $failureCount >= $failureThreshold
                    ? now()->addSeconds($circuitSeconds)
                    : null,
            ])->save();
        });
    }

    private function markAccepted(
        GatewayMessage $message,
        ProviderAccount $provider,
        ProviderResult $result,
    ): GatewayMessage {
        DB::transaction(function () use ($message, $provider, $result): void {
            $message->forceFill([
                'status' => 'provider_accepted',
                'accepted_provider_account_id' => $provider->id,
                'provider_message_id' => $result->providerMessageId,
                'provider_accepted_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            $this->appendEventOnce($message->id, 'provider_accepted');
        });

        return $message->fresh() ?? $message;
    }

    private function markOutcomeUnknown(GatewayMessage $message, ProviderResult $result): GatewayMessage
    {
        DB::transaction(function () use ($message, $result): void {
            $message->forceFill([
                'status' => 'outcome_unknown',
                'outcome_unknown_at' => now(),
                'last_error_code' => $this->sanitize($result->errorCode, 120),
                'last_error_message' => $this->sanitize($result->errorMessage, 500),
            ])->save();

            $this->appendEventOnce($message->id, 'outcome_unknown');
        });

        return $message->fresh() ?? $message;
    }

    private function markFailed(
        GatewayMessage $message,
        string $errorCode,
        string $errorMessage,
    ): GatewayMessage {
        DB::transaction(function () use ($message, $errorCode, $errorMessage): void {
            $message->forceFill([
                'status' => 'failed',
                'failed_at' => $message->failed_at ?? now(),
                'last_error_code' => $this->sanitize($errorCode, 120),
                'last_error_message' => $this->sanitize($errorMessage, 500),
            ])->save();

            $this->appendEventOnce($message->id, 'failed');
        });

        return $message->fresh() ?? $message;
    }

    private function markExpired(GatewayMessage $message): GatewayMessage
    {
        DB::transaction(function () use ($message): void {
            $message->forceFill([
                'status' => 'expired',
                'last_error_code' => 'message_expired',
                'last_error_message' => 'Pesan melewati batas waktu pengiriman.',
            ])->save();

            $this->appendEventOnce($message->id, 'expired');
        });

        return $message->fresh() ?? $message;
    }

    private function isExpired(GatewayMessage $message): bool
    {
        return $message->expires_at !== null && now()->greaterThanOrEqualTo($message->expires_at);
    }

    private function appendEventOnce(int $messageId, string $type): void
    {
        if (DB::table('message_events')
            ->where('gateway_message_id', $messageId)
            ->where('type', $type)
            ->exists()) {
            return;
        }

        $this->appendEvent($messageId, $type);
    }

    private function appendEvent(int $messageId, string $type): void
    {
        $now = now();

        DB::table('message_events')->insert([
            'gateway_message_id' => $messageId,
            'type' => $type,
            'source' => 'system',
            'data' => null,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    private function sanitize(?string $value, int $length): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $length);
    }
}
