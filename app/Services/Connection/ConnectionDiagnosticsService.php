<?php

namespace App\Services\Connection;

use App\Models\GatewayMessage;
use App\Models\MessageAttempt;
use App\Models\WhatsAppConnection;
final class ConnectionDiagnosticsService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(WhatsAppConnection $connection): array
    {
        $providerId = $connection->provider_account_id;
        $since = now()->subDay();

        $messageQuery = GatewayMessage::query()
            ->where('whatsapp_connection_id', $connection->id);

        $recentMessages = (clone $messageQuery)
            ->where('created_at', '>=', $since)
            ->selectRaw("count(*) as total")
            ->selectRaw("sum(case when status = 'provider_accepted' then 1 else 0 end) as accepted")
            ->selectRaw("sum(case when status in ('failed', 'dead_letter') then 1 else 0 end) as failed")
            ->selectRaw("sum(case when status = 'outcome_unknown' then 1 else 0 end) as outcome_unknown")
            ->first();

        $total = (int) ($recentMessages?->total ?? 0);
        $accepted = (int) ($recentMessages?->accepted ?? 0);
        $failed = (int) ($recentMessages?->failed ?? 0);

        $attempts = $providerId === null ? collect() : MessageAttempt::query()
            ->where('provider_account_id', $providerId)
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $lastSuccess = (clone $messageQuery)
            ->where('status', 'provider_accepted')
            ->latest('provider_accepted_at')
            ->first();

        $problems = [];

        if ($connection->status_detail) {
            $problems[] = [
                'code' => $connection->next_action ?? 'connection_not_ready',
                'message' => (string) $connection->status_detail,
            ];
        }

        if ($providerId !== null) {
            $provider = $connection->providerAccount;

            if ($provider?->circuit_open_until !== null && $provider->circuit_open_until->isFuture()) {
                $problems[] = [
                    'code' => 'provider_unavailable',
                    'message' => 'Circuit breaker aktif hingga '.$provider->circuit_open_until->toIso8601String(),
                ];
            }
        }

        return [
            'connection_id' => (string) $connection->uuid,
            'period' => '24h',
            'messages' => [
                'total' => $total,
                'accepted' => $accepted,
                'failed' => $failed,
                'outcome_unknown' => (int) ($recentMessages?->outcome_unknown ?? 0),
                'success_rate' => $total > 0 ? round($accepted / $total, 4) : null,
            ],
            'last_successful_send' => $lastSuccess ? [
                'message_id' => (string) $lastSuccess->uuid,
                'at' => $lastSuccess->provider_accepted_at?->toIso8601String(),
            ] : null,
            'recent_attempts' => $attempts->map(fn (MessageAttempt $attempt): array => [
                'sequence' => (int) $attempt->sequence,
                'status' => (string) $attempt->status,
                'error_code' => $attempt->error_code,
                'latency_ms' => $attempt->latency_ms,
                'at' => $attempt->created_at?->toIso8601String(),
            ])->all(),
            'fallback_chain' => $connection->isProviderRoute()
                ? app(ConnectionFallbackManager::class)->listSteps($connection)
                : [],
            'problems' => $problems,
        ];
    }
}
