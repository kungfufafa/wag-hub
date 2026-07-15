<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderDriver;
use App\Domain\Delivery\OutboundText;
use App\Domain\Delivery\ProviderResult;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final readonly class WahaDriver implements ProviderDriver
{
    public function __construct(
        private WahaResponseClassifier $responses,
        private TransportFailureClassifier $transportFailures,
        private ProviderEndpointGuard $endpoints,
    ) {}

    public function send(ProviderAccount $account, OutboundText $message): ProviderResult
    {
        $configuration = $this->configuration($account);
        $baseUrl = $this->nonEmptyString($configuration['base_url'] ?? null);
        $session = $this->nonEmptyString($configuration['session'] ?? null);

        if ($baseUrl === null || $session === null) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_configuration_invalid',
                errorMessage: 'Konfigurasi WAHA belum lengkap.',
            );
        }

        $endpoint = rtrim($baseUrl, '/').'/api/sendText';

        try {
            $this->endpoints->assertAllowed($endpoint);
        } catch (InvalidArgumentException) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_endpoint_not_allowed',
                errorMessage: 'Endpoint WAHA tidak diizinkan.',
            );
        }

        $request = Http::acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->timeout($this->timeout($account))
            ->connectTimeout(min(5, $this->timeout($account)));

        $apiKey = $this->nonEmptyString($configuration['api_key'] ?? null);

        if ($apiKey !== null) {
            $request = $request->withHeaders(['X-Api-Key' => $apiKey]);
        }

        try {
            $response = $request->post($endpoint, [
                'session' => $session,
                'chatId' => $message->wahaChatId(),
                'text' => $message->body,
            ]);
        } catch (ConnectionException $exception) {
            return $this->transportFailures->classifyException($exception);
        }

        return $this->responses->classify($response->status(), $response->body());
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(ProviderAccount $account): array
    {
        return is_array($account->configuration) ? $account->configuration : [];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function timeout(ProviderAccount $account): int
    {
        return max(1, min(60, (int) ($account->timeout_seconds ?: 15)));
    }
}
