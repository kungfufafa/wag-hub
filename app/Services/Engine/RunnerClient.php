<?php

namespace App\Services\Engine;

use App\Exceptions\WhatsAppEngineException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class RunnerClient
{
    public function request(string $method, string $path, array $payload = []): array
    {
        $token = trim((string) config('gateway.engine.baileys_token'));
        $base = rtrim((string) config('gateway.engine.baileys_url'), '/');
        if ($token === '' || $base === '') {
            throw new WhatsAppEngineException('WhatsApp runner is not configured.', 503, 'engine_unconfigured', true);
        }
        try {
            $response = Http::acceptJson()->asJson()->withoutRedirecting()->withToken($token)
                ->connectTimeout(2)->timeout($path === '/health' ? 2 : 30)
                ->send($method, $base.$path, $method === 'GET' ? ['query' => $payload] : ['json' => $payload]);
        } catch (ConnectionException) {
            throw new WhatsAppEngineException('WhatsApp runner cannot be reached.', 503, 'engine_unreachable', true);
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new WhatsAppEngineException('Invalid WhatsApp runner response.', 502, 'engine_response_invalid', true);
        }
        if (! $response->successful() && ! in_array($data['status'] ?? null, ['failed', 'unknown'], true)) {
            throw new WhatsAppEngineException('WhatsApp runner rejected the operation.', $response->status(), $data['error_code'] ?? 'engine_rejected', $response->serverError());
        }

        return $data;
    }
}
