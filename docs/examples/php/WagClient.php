<?php

/**
 * Copy this class into a consuming application.
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
     * @param  array{type: string, value: string}  $recipient
     * @param  array{type: string, text?: string, attachment?: array<string, mixed>}  $message
     * @return array<string, mixed>
     */
    public function send(
        ?string $connectionId,
        array $recipient,
        array $message,
        string $idempotencyKey,
    ): array {
        $payload = [
            'recipient' => $recipient,
            'message' => $message,
        ];

        if ($connectionId !== null && $connectionId !== '') {
            $payload['connection_id'] = $connectionId;
        }

        $response = $this->request('POST', '/api/v1/messages', $payload, [
            'Idempotency-Key: '.$idempotencyKey,
        ]);

        if (($response['error']['code'] ?? null) === 'delivery_outcome_unknown') {
            // Do not invent a new idempotency key. Read the same message again.
        }

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function connections(): array
    {
        return $this->request('GET', '/api/v1/connections')['data'] ?? [];
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
            throw new RuntimeException('WAG Hub request failed: '.$code);
        }

        return $decoded;
    }
}
