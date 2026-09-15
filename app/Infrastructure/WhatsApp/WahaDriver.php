<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderDriver;
use App\Contracts\WhatsApp\ProviderNumberChecker;
use App\Domain\Delivery\AttachmentKind;
use App\Domain\Delivery\OutboundMessage;
use App\Domain\Delivery\ProviderResult;
use App\Domain\NumberCheck\NumberCheckResult;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final readonly class WahaDriver implements ProviderDriver, ProviderNumberChecker
{
    public function __construct(
        private WahaResponseClassifier $responses,
        private TransportFailureClassifier $transportFailures,
        private ProviderEndpointGuard $endpoints,
    ) {}

    public function send(ProviderAccount $account, OutboundMessage $message): ProviderResult
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

        $audioMime = strtolower((string) ($message->attachment?->resolvedMimeType() ?? ''));

        if ($message->attachment?->kind === AttachmentKind::Audio
            && ! (str_starts_with($audioMime, 'audio/ogg') || str_starts_with($audioMime, 'audio/opus'))) {
            return ProviderResult::rejected(
                errorCode: 'attachment_format_unsupported',
                errorMessage: 'WAHA menerima voice attachment dalam OGG/Opus.',
            );
        }

        [$path, $payload] = $this->sendRequest($session, $message);
        $endpoint = rtrim($baseUrl, '/').$path;

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
            $response = $request->post($endpoint, $payload);
        } catch (ConnectionException $exception) {
            return $this->transportFailures->classifyException($exception);
        }

        return $this->responses->classify($response->status(), $response->body());
    }

    /**
     * Map the outbound message onto the matching WAHA send endpoint.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function sendRequest(string $session, OutboundMessage $message): array
    {
        $payload = [
            'session' => $session,
            'chatId' => $message->wahaChatId(),
        ];
        $attachment = $message->attachment;

        if ($attachment === null) {
            return ['/api/sendText', $payload + ['text' => $message->body]];
        }

        $file = [
            'mimetype' => $attachment->resolvedMimeType(),
            'url' => $attachment->url,
        ];

        if ($attachment->kind !== AttachmentKind::Audio) {
            $file['filename'] = $attachment->resolvedFilename();
        }

        $payload['file'] = $file;
        $caption = $message->caption();

        if ($caption !== null) {
            $payload['caption'] = $caption;
        }

        $path = match ($attachment->kind) {
            AttachmentKind::Image => '/api/sendImage',
            AttachmentKind::Document => '/api/sendFile',
            AttachmentKind::Video => '/api/sendVideo',
            AttachmentKind::Audio => '/api/sendVoice',
        };

        if ($attachment->kind === AttachmentKind::Audio) {
            // WAHA voice messages must already be OGG/Opus; never trigger
            // the provider's automatic transcoding path.
            $payload['convert'] = false;
        }

        return [$path, $payload];
    }

    public function checkNumber(ProviderAccount $account, string $recipient): NumberCheckResult
    {
        $configuration = $this->configuration($account);
        $baseUrl = $this->nonEmptyString($configuration['base_url'] ?? null);
        $session = $this->nonEmptyString($configuration['session'] ?? null);

        if ($baseUrl === null || $session === null) {
            return NumberCheckResult::unknown('provider_configuration_invalid');
        }

        $endpoint = rtrim($baseUrl, '/').'/api/contacts/check-exists';

        try {
            $this->endpoints->assertAllowed($endpoint);
        } catch (InvalidArgumentException) {
            return NumberCheckResult::unknown('provider_endpoint_not_allowed');
        }

        $request = Http::acceptJson()
            ->withoutRedirecting()
            ->timeout($this->timeout($account))
            ->connectTimeout(min(5, $this->timeout($account)));
        $apiKey = $this->nonEmptyString($configuration['api_key'] ?? null);

        if ($apiKey !== null) {
            $request = $request->withHeaders(['X-Api-Key' => $apiKey]);
        }

        try {
            $response = $request->get($endpoint, [
                'phone' => $recipient,
                'session' => $session,
            ]);
        } catch (ConnectionException) {
            return NumberCheckResult::unknown('provider_unavailable');
        }

        if (! $response->successful()) {
            return NumberCheckResult::unknown('provider_unavailable', $response->status());
        }

        return match ($response->json('numberExists')) {
            true => NumberCheckResult::registered($response->status()),
            false => NumberCheckResult::notRegistered($response->status()),
            default => NumberCheckResult::unknown(httpStatus: $response->status()),
        };
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
