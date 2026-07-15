<?php

namespace App\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderResult;

final class WahaResponseClassifier
{
    public function classify(int $httpStatus, string $body): ProviderResult
    {
        if ($httpStatus >= 200 && $httpStatus < 300) {
            $payload = $this->decodeObject($body);

            if ($payload === null || ! $this->isExplicitlyProcessable($payload)) {
                return ProviderResult::outcomeUnknown(
                    httpStatus: $httpStatus,
                    errorCode: 'indeterminate_provider_response',
                    errorMessage: 'WAHA mengembalikan respons yang tidak dapat dipastikan.',
                );
            }

            return ProviderResult::accepted(
                httpStatus: $httpStatus,
                providerMessageId: $this->providerMessageId($payload),
                metadata: $this->safeMetadata($payload),
            );
        }

        $message = $this->errorMessage($body);

        if (in_array($httpStatus, [400, 422], true)) {
            return ProviderResult::rejected(
                httpStatus: $httpStatus,
                errorCode: 'invalid_message',
                errorMessage: $message,
            );
        }

        return ProviderResult::providerFailed(
            httpStatus: $httpStatus,
            errorCode: $this->providerErrorCode($httpStatus),
            errorMessage: $message,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeObject(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $payload = json_decode($body, true);

        return is_array($payload) && ! array_is_list($payload) && $payload !== [] ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isExplicitlyProcessable(array $payload): bool
    {
        if ($this->providerMessageId($payload) !== null) {
            return true;
        }

        $status = strtolower(trim((string) ($payload['status'] ?? '')));

        return in_array($status, ['success', 'sent', 'ok'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerMessageId(array $payload): ?string
    {
        $id = $payload['id'] ?? data_get($payload, '_data.id') ?? data_get($payload, 'key.id');

        return is_scalar($id) && trim((string) $id) !== '' ? mb_substr((string) $id, 0, 255) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeMetadata(array $payload): array
    {
        return array_filter([
            'status' => is_scalar($payload['status'] ?? null) ? $payload['status'] : null,
            'timestamp' => is_scalar($payload['timestamp'] ?? null) ? $payload['timestamp'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function errorMessage(string $body): string
    {
        $payload = json_decode($body, true);
        $message = is_array($payload)
            ? ($payload['error'] ?? $payload['message'] ?? 'WAHA menolak request.')
            : 'WAHA menolak request.';

        return mb_substr(is_scalar($message) ? (string) $message : 'WAHA menolak request.', 0, 500);
    }

    private function providerErrorCode(int $httpStatus): string
    {
        return match ($httpStatus) {
            401, 403 => 'provider_authentication_failed',
            429 => 'provider_rate_limited',
            default => 'provider_unavailable',
        };
    }
}
