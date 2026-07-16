<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderDriver;
use App\Domain\Delivery\OutboundText;
use App\Domain\Delivery\ProviderResult;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final readonly class WabaDriver implements ProviderDriver
{
    public function __construct(
        private WabaResponseClassifier $responses,
        private TransportFailureClassifier $transportFailures,
        private ProviderEndpointGuard $endpoints,
    ) {}

    public function send(ProviderAccount $account, OutboundText $message): ProviderResult
    {
        $configuration = is_array($account->configuration) ? $account->configuration : [];
        $baseUrl = $this->nonEmptyString($configuration['base_url'] ?? null);
        $apiVersion = $this->nonEmptyString($configuration['api_version'] ?? null);
        $phoneNumberId = $this->nonEmptyString($configuration['phone_number_id'] ?? null);
        $accessToken = $this->nonEmptyString($configuration['access_token'] ?? null);

        if (
            $baseUrl === null
            || $accessToken === null
            || $apiVersion === null
            || preg_match('/^v\d+\.\d+$/', $apiVersion) !== 1
            || $phoneNumberId === null
            || preg_match('/^\d+$/', $phoneNumberId) !== 1
        ) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_configuration_invalid',
                errorMessage: 'Konfigurasi WABA belum lengkap atau tidak valid.',
            );
        }

        $endpoint = rtrim($baseUrl, '/')."/{$apiVersion}/{$phoneNumberId}/messages";

        try {
            $this->endpoints->assertAllowed($endpoint);
        } catch (InvalidArgumentException) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_endpoint_not_allowed',
                errorMessage: 'Endpoint WABA tidak diizinkan.',
            );
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($accessToken)
                ->withoutRedirecting()
                ->timeout($this->timeout($account))
                ->connectTimeout(min(5, $this->timeout($account)))
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $message->recipient,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message->body,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            return $this->transportFailures->classifyException($exception);
        }

        return $this->responses->classify($response->status(), $response->body());
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function timeout(ProviderAccount $account): int
    {
        return max(1, min(60, (int) ($account->timeout_seconds ?: 15)));
    }
}
