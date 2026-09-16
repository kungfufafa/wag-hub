<?php

namespace App\Infrastructure\WhatsApp;

use App\Models\ProviderAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Thin HTTP client for the WAHA session lifecycle API (the engine that powers
 * our self-hosted Baileys/NOWEB connection). Endpoints follow the current WAHA
 * contract and are also implemented by the bundled mock engine under engine/.
 */
final readonly class WahaSessionClient
{
    public function __construct(
        private ProviderEndpointGuard $endpoints,
    ) {}

    /**
     * Fetch the raw session status document, e.g.
     * {"name":"default","status":"WORKING","me":{"id":"628...@c.us","pushName":"Bot"}}.
     *
     * @return array<string, mixed>|null
     */
    public function status(ProviderAccount $account): ?array
    {
        return $this->getJson($account, '/api/sessions/'.$this->session($account));
    }

    /**
     * Create (if needed) and start the session so it begins pairing.
     * Returns the resulting status document when available.
     *
     * @return array<string, mixed>|null
     */
    public function start(ProviderAccount $account): ?array
    {
        $session = $this->session($account);

        // Create-or-ignore: newer WAHA merges create+start, older needs create first.
        $this->postJson($account, '/api/sessions', [
            'name' => $session,
            'start' => true,
            'config' => [
                'webhooks' => [[
                    'url' => url('/webhooks/whatsapp/'.$account->uuid),
                    'events' => ['message', 'message.any', 'session.status'],
                ]],
            ],
        ]);

        $this->postJson($account, '/api/sessions/'.$session.'/start', []);

        return $this->status($account);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function stop(ProviderAccount $account): ?array
    {
        $this->postJson($account, '/api/sessions/'.$this->session($account).'/stop', []);

        return $this->status($account);
    }

    /**
     * Log the paired device out and clear its credentials on the engine.
     *
     * @return array<string, mixed>|null
     */
    public function logout(ProviderAccount $account): ?array
    {
        $this->postJson($account, '/api/sessions/'.$this->session($account).'/logout', []);

        return $this->status($account);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restart(ProviderAccount $account): ?array
    {
        $this->postJson($account, '/api/sessions/'.$this->session($account).'/restart', []);

        return $this->status($account);
    }

    /**
     * Fetch the current pairing QR as a data URI, or null when unavailable
     * (e.g. the session is already paired or still starting).
     */
    public function qrDataUri(ProviderAccount $account): ?string
    {
        $session = $this->session($account);
        $path = '/api/'.$session.'/auth/qr';

        try {
            $response = $this->request($account, $path)
                ->get($this->url($account, $path), ['format' => 'image']);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if (str_contains($contentType, 'image')) {
            $mime = str_contains($contentType, 'jpeg') ? 'image/jpeg' : 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode($response->body());
        }

        // Some engines return {"mimetype":"image/png","data":"<base64>"} as JSON.
        $data = $response->json('data');
        $mime = InboxPayload::string($response->json('mimetype')) ?? 'image/png';

        if (is_string($data) && $data !== '') {
            $data = str_contains($data, ',') ? explode(',', $data, 2)[1] : $data;

            return 'data:'.$mime.';base64,'.$data;
        }

        return null;
    }

    private function session(ProviderAccount $account): string
    {
        $session = InboxPayload::string(($account->configuration ?? [])['session'] ?? null);

        if ($session === null) {
            throw new InvalidArgumentException('Session WAHA belum dikonfigurasi.');
        }

        return rawurlencode($session);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function getJson(ProviderAccount $account, string $path, array $query = []): ?array
    {
        try {
            $response = $this->request($account, $path)->get($this->url($account, $path), $query);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function postJson(ProviderAccount $account, string $path, array $payload): ?array
    {
        try {
            $response = $this->request($account, $path)->asJson()->post($this->url($account, $path), $payload);
        } catch (Throwable) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    private function request(ProviderAccount $account, string $path): PendingRequest
    {
        $this->endpoints->assertAllowed($this->url($account, $path));

        $request = Http::acceptJson()
            ->withoutRedirecting()
            ->timeout($this->timeout($account))
            ->connectTimeout(min(5, $this->timeout($account)));

        $apiKey = InboxPayload::string(($account->configuration ?? [])['api_key'] ?? null);

        if ($apiKey !== null) {
            $request = $request->withHeaders(['X-Api-Key' => $apiKey]);
        }

        return $request;
    }

    private function url(ProviderAccount $account, string $path): string
    {
        $baseUrl = InboxPayload::string(($account->configuration ?? [])['base_url'] ?? null);

        if ($baseUrl === null) {
            throw new InvalidArgumentException('Base URL WAHA belum dikonfigurasi.');
        }

        return rtrim($baseUrl, '/').$path;
    }

    private function timeout(ProviderAccount $account): int
    {
        return max(1, min(60, (int) ($account->timeout_seconds ?: 15)));
    }
}
