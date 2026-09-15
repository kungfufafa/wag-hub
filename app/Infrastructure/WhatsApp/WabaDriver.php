<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderDriver;
use App\Contracts\WhatsApp\ProviderNumberChecker;
use App\Domain\Delivery\OutboundMessage;
use App\Domain\Delivery\ProviderResult;
use App\Domain\NumberCheck\NumberCheckResult;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final readonly class WabaDriver implements ProviderDriver, ProviderNumberChecker
{
    public function __construct(
        private WabaResponseClassifier $responses,
        private TransportFailureClassifier $transportFailures,
        private ProviderEndpointGuard $endpoints,
    ) {}

    public function send(ProviderAccount $account, OutboundMessage $message): ProviderResult
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

        if ($message->attachment?->size !== null) {
            $limit = match ($message->attachment->kind->value) {
                'image' => 5 * 1024 * 1024,
                'audio', 'video' => 16 * 1024 * 1024,
                'document' => 100 * 1024 * 1024,
                default => null,
            };

            if ($limit !== null && $message->attachment->size > $limit) {
                return ProviderResult::rejected(
                    errorCode: 'attachment_size_unsupported',
                    errorMessage: 'Ukuran attachment melebihi batas WABA untuk jenis media ini.',
                );
            }
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
                    ...$this->messageObject($message),
                ]);
        } catch (ConnectionException $exception) {
            return $this->transportFailures->classifyException($exception);
        }

        return $this->responses->classify($response->status(), $response->body());
    }

    /**
     * Meta Cloud API `type` + matching media object (image/document/video/audio via link).
     *
     * @return array<string, mixed>
     */
    private function messageObject(OutboundMessage $message): array
    {
        $attachment = $message->attachment;

        if ($attachment === null) {
            return [
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $message->body,
                ],
            ];
        }

        $media = ['link' => $attachment->url];
        $caption = $message->caption();

        if ($caption !== null) {
            $media['caption'] = $caption;
        }

        if ($attachment->kind->supportsFilename()) {
            $media['filename'] = $attachment->resolvedFilename();
        }

        $type = $attachment->kind->value;

        return [
            'type' => $type,
            $type => $media,
        ];
    }

    public function checkNumber(ProviderAccount $account, string $recipient): NumberCheckResult
    {
        return NumberCheckResult::unsupported();
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
