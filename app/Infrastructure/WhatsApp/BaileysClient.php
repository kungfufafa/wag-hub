<?php

namespace App\Infrastructure\WhatsApp;

use App\Exceptions\WhatsAppEngineException;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final readonly class BaileysClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('gateway.engine.baileys_url')) !== ''
            && trim((string) config('gateway.engine.baileys_token')) !== '';
    }

    public function health(): bool
    {
        try {
            return ($this->request('GET', '/health')['ok'] ?? false) === true;
        } catch (WhatsAppEngineException) {
            return false;
        }
    }

    public function sessionId(ProviderAccount $account): string
    {
        // IDs are assigned by the Hub, never by a client or editable provider form.
        return 'wgh-'.$account->uuid;
    }

    public function start(ProviderAccount $account, ?string $phone = null): array
    {
        return $this->request('POST', '/sessions', [
            'id' => $this->sessionId($account),
            'mode' => $phone ? 'pairing' : 'qr',
            'phone' => $phone,
        ]);
    }

    public function status(ProviderAccount $account): array
    {
        return $this->request('GET', '/sessions/'.$this->sessionId($account));
    }

    public function logout(ProviderAccount $account, bool $logout = true): array
    {
        return $this->request('DELETE', '/sessions/'.$this->sessionId($account), ['logout' => $logout]);
    }

    public function send(ProviderAccount $account, string $phone, string $text, string $key): array
    {
        return $this->request('POST', '/sessions/'.$this->sessionId($account).'/send', [
            'phone' => $phone, 'text' => $text, 'idempotency_key' => $key,
        ], timeout: $account->timeout_seconds ?: 20, sending: true);
    }

    public function message(ProviderAccount $account, string $key): array
    {
        return $this->request('GET', '/sessions/'.$this->sessionId($account).'/messages/'.rawurlencode($key), timeout: 3);
    }

    public function checkNumber(ProviderAccount $account, string $phone): array
    {
        return $this->request('POST', '/sessions/'.$this->sessionId($account).'/numbers/check', ['phone' => $phone]);
    }

    private function request(string $method, string $path, array $data = [], int $timeout = 20, bool $sending = false): array
    {
        if (! $this->isConfigured()) {
            throw new WhatsAppEngineException('Engine WAG Hub belum dikonfigurasi.', 503, 'engine_unconfigured', true);
        }

        try {
            $response = Http::acceptJson()->asJson()->withoutRedirecting()
                ->withToken(trim((string) config('gateway.engine.baileys_token')))
                ->connectTimeout(2)->timeout($path === '/health' ? 2 : max(1, min(60, $timeout)))
                ->send($method, rtrim((string) config('gateway.engine.baileys_url'), '/').$path,
                    $method === 'GET' ? [] : ['json' => $data]);
        } catch (ConnectionException) {
            throw new WhatsAppEngineException('Engine WAG Hub tidak dapat dihubungi.', 503, 'engine_unreachable');
        }

        $payload = $response->json();
        if ($sending && is_array($payload) && in_array($payload['status'] ?? null, ['sent', 'failed', 'unknown'], true)
            && (($payload['status'] ?? null) !== 'sent' || ($response->successful() && ($payload['ok'] ?? false)))) {
            return $payload;
        }

        if (! $response->successful() || ! is_array($payload)) {
            throw new WhatsAppEngineException('Respons engine WAG Hub tidak valid.', $response->successful() ? 502 : $response->status(), 'engine_response_invalid');
        }

        return $payload;
    }
}
