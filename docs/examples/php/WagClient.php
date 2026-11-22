<?php

/**
 * Copy this class into a consuming application. It talks to the v2 Connection API.
 *
 * Required environment:
 *   WAG_URL=https://gateway.example.com
 *   WAG_TOKEN=wgh_...
 */
final class WagClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    /**
     * The shortest send: POST /api/v2/messages on the application's default connection.
     * $to may be local ("0812-3456-7890"). Pass $media as ['image' => url] or
     * ['document' => url] to send a file by URL ($text becomes the caption).
     * Always 202 with data.status = queued|sent|delivered|read|failed.
     *
     * @param  array{image?: string, document?: string, voice?: string, connection?: string}  $options
     * @return array<string, mixed>
     */
    public function sendMessage(string $to, string $text, string $idempotencyKey, array $options = []): array
    {
        // Retry a timeout with the SAME key: the original message comes back, never a duplicate.
        return $this->request('POST', '/api/v2/messages', array_filter(
            ['to' => $to, 'text' => $text, ...$options],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ), ['Idempotency-Key: '.$idempotencyKey])['data'] ?? [];
    }

    /**
     * Send through a connection. A managed number answers 202 (queued on its v2 session);
     * a provider route answers 201 once a provider accepted the message.
     *
     * @return array<string, mixed>
     */
    public function send(string $connectionId, string $to, string $text, string $idempotencyKey): array
    {
        // On a timeout or delivery_outcome_unknown, retry with the SAME idempotency key:
        // the original message is returned instead of sending twice.
        return $this->request('POST', '/api/v2/connections/'.rawurlencode($connectionId).'/messages', [
            'to' => $to,
            'type' => 'text',
            'text' => $text,
        ], ['Idempotency-Key: '.$idempotencyKey]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function connections(): array
    {
        return $this->request('GET', '/api/v2/connections')['data'] ?? [];
    }

    /**
     * Status, QR, or pairing code of one connection.
     *
     * @return array<string, mixed>
     */
    public function connection(string $connectionId): array
    {
        return $this->request('GET', '/api/v2/connections/'.rawurlencode($connectionId))['data'] ?? [];
    }

    /**
     * Create a managed number (QR) once per idempotency key.
     *
     * @return array<string, mixed>
     */
    public function createManagedNumber(string $name, string $idempotencyKey): array
    {
        return $this->request('POST', '/api/v2/connections', [
            'type' => 'managed_number',
            'name' => $name,
            'auth_method' => 'qr',
        ], ['Idempotency-Key: '.$idempotencyKey])['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = [], array $headers = []): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/').$path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: application/json',
                'Authorization: Bearer '.$this->token,
                'Content-Type: application/json',
            ], $headers),
            CURLOPT_POSTFIELDS => $method === 'GET' ? null : json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($body) ? (json_decode($body, true) ?: []) : [];

        if ($status >= 400) {
            $code = is_array($decoded['error'] ?? null) ? ($decoded['error']['code'] ?? $status) : $status;
            $retryable = (bool) ($decoded['error']['retryable'] ?? false);
            throw new RuntimeException('WAG Hub request failed: '.$code.($retryable ? ' (retryable)' : ''));
        }

        return $decoded;
    }
}
