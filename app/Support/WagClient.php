<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Minimal application-facing WAG Hub client.
 *
 * Usage:
 *   $wag = WagClient::fromEnv();
 *   $wag->messages()->send(recipient: '6281234567890', text: 'Hello', idempotencyKey: 'order-1');
 */
final class WagClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly ?string $defaultConnectionId = null,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            baseUrl: rtrim((string) env('WAG_URL', ''), '/'),
            token: (string) env('WAG_TOKEN', ''),
            defaultConnectionId: env('WAG_CONNECTION_ID') ?: null,
        );
    }

    public function messages(): WagMessagesClient
    {
        return new WagMessagesClient($this);
    }

    public function connections(): WagConnectionsClient
    {
        return new WagConnectionsClient($this);
    }

    public function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl.'/api/v1')
            ->withToken($this->token)
            ->acceptJson()
            ->asJson();
    }

    public function defaultConnectionId(): ?string
    {
        return $this->defaultConnectionId;
    }
}

final class WagMessagesClient
{
    public function __construct(private readonly WagClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function send(
        string $recipient,
        string $text,
        string $idempotencyKey,
        ?string $connectionId = null,
        string $purpose = 'notification',
        string $mode = 'sync',
    ): array {
        $connectionId ??= $this->client->defaultConnectionId();

        $payload = [
            'recipient' => ['type' => 'phone', 'value' => $recipient],
            'message' => ['type' => 'text', 'text' => $text],
            'purpose' => $purpose,
            'mode' => $mode,
        ];

        if ($connectionId !== null) {
            $payload['connection_id'] = $connectionId;
        } else {
            $payload['route_key'] = 'default';
        }

        $response = $this->client->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/messages', $payload);

        $response->throw();

        return $response->json();
    }
}

final class WagConnectionsClient
{
    public function __construct(private readonly WagClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $response = $this->client->request()->get('/connections');
        $response->throw();

        return $response->json();
    }
}
