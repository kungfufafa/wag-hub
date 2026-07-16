<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderDriver;
use App\Contracts\WhatsApp\ProviderNumberChecker;
use App\Domain\Delivery\OutboundText;
use App\Domain\Delivery\ProviderResult;
use App\Domain\NumberCheck\NumberCheckResult;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final readonly class FonnteDriver implements ProviderDriver, ProviderNumberChecker
{
    public function __construct(
        private FonnteResponseClassifier $responses,
        private TransportFailureClassifier $transportFailures,
        private ProviderEndpointGuard $endpoints,
    ) {}

    public function send(ProviderAccount $account, OutboundText $message): ProviderResult
    {
        $configuration = $this->configuration($account);
        $endpoint = $this->nonEmptyString($configuration['endpoint'] ?? null);
        $token = $this->nonEmptyString($configuration['token'] ?? null);

        if ($endpoint === null || $token === null) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_configuration_invalid',
                errorMessage: 'Konfigurasi Fonnte belum lengkap.',
            );
        }

        try {
            $this->endpoints->assertAllowed($endpoint);
        } catch (InvalidArgumentException) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_endpoint_not_allowed',
                errorMessage: 'Endpoint Fonnte tidak diizinkan.',
            );
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Authorization' => $token])
                ->asMultipart()
                ->withoutRedirecting()
                ->timeout($this->timeout($account))
                ->connectTimeout(min(5, $this->timeout($account)))
                ->post($endpoint, [
                    [
                        'name' => 'target',
                        'contents' => $message->recipient,
                    ],
                    [
                        'name' => 'message',
                        'contents' => $message->body,
                    ],
                    [
                        'name' => 'countryCode',
                        'contents' => '62',
                    ],
                ]);
        } catch (ConnectionException $exception) {
            return $this->transportFailures->classifyException($exception);
        }

        return $this->responses->classify($response->status(), $response->body());
    }

    public function checkNumber(ProviderAccount $account, string $recipient): NumberCheckResult
    {
        $configuration = $this->configuration($account);
        $endpoint = $this->nonEmptyString(
            $configuration['validate_endpoint'] ?? 'https://api.fonnte.com/validate',
        );
        $token = $this->nonEmptyString($configuration['token'] ?? null);

        if ($endpoint === null || $token === null) {
            return NumberCheckResult::unknown('provider_configuration_invalid');
        }

        try {
            $this->endpoints->assertAllowed($endpoint);
        } catch (InvalidArgumentException) {
            return NumberCheckResult::unknown('provider_endpoint_not_allowed');
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Authorization' => $token])
                ->asMultipart()
                ->withoutRedirecting()
                ->timeout($this->timeout($account))
                ->connectTimeout(min(5, $this->timeout($account)))
                ->post($endpoint, [
                    ['name' => 'target', 'contents' => $recipient],
                    ['name' => 'countryCode', 'contents' => '62'],
                ]);
        } catch (ConnectionException) {
            return NumberCheckResult::unknown('provider_unavailable');
        }

        if (! $response->successful() || $response->json('status') !== true) {
            return NumberCheckResult::unknown('provider_unavailable', $response->status());
        }

        if ($this->containsRecipient($response->json('registered'), $recipient)) {
            return NumberCheckResult::registered($response->status());
        }

        if ($this->containsRecipient($response->json('not_registered'), $recipient)) {
            return NumberCheckResult::notRegistered($response->status());
        }

        return NumberCheckResult::unknown(httpStatus: $response->status());
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

    private function containsRecipient(mixed $numbers, string $recipient): bool
    {
        if (! is_array($numbers)) {
            return false;
        }

        foreach ($numbers as $number) {
            if (is_scalar($number) && preg_replace('/\D+/', '', (string) $number) === $recipient) {
                return true;
            }
        }

        return false;
    }
}
