<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderDriver;
use App\Domain\Delivery\OutboundText;
use App\Domain\Delivery\ProviderResult;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final readonly class GowaDriver implements ProviderDriver
{
    public function __construct(
        private GowaResponseClassifier $responses,
        private TransportFailureClassifier $transportFailures,
        private ProviderEndpointGuard $endpoints,
    ) {}

    public function send(ProviderAccount $account, OutboundText $message): ProviderResult
    {
        $configuration = is_array($account->configuration) ? $account->configuration : [];
        $baseUrl = $this->nonEmptyString($configuration['base_url'] ?? null);
        $username = $this->nonEmptyString($configuration['username'] ?? null);
        $password = $this->nonEmptyString($configuration['password'] ?? null);

        if ($baseUrl === null || $username === null || $password === null) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_configuration_invalid',
                errorMessage: 'Konfigurasi GOWA belum lengkap.',
            );
        }

        $endpoint = rtrim($baseUrl, '/').'/send/message';

        try {
            $this->endpoints->assertAllowed($endpoint);
        } catch (InvalidArgumentException) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_endpoint_not_allowed',
                errorMessage: 'Endpoint GOWA tidak diizinkan.',
            );
        }

        $request = Http::acceptJson()
            ->asJson()
            ->withBasicAuth($username, $password)
            ->withoutRedirecting()
            ->timeout($this->timeout($account))
            ->connectTimeout(min(5, $this->timeout($account)));
        $deviceId = $this->nonEmptyString($configuration['device_id'] ?? null);

        if ($deviceId !== null) {
            $request = $request->withHeaders(['X-Device-Id' => $deviceId]);
        }

        try {
            $response = $request->post($endpoint, [
                'phone' => $message->recipient.'@s.whatsapp.net',
                'message' => $message->body,
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
