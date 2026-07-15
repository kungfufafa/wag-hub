<?php

namespace App\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderResult;

final class FonnteResponseClassifier
{
    public function classify(int $httpStatus, string $body): ProviderResult
    {
        if ($httpStatus >= 200 && $httpStatus < 300) {
            $payload = $this->decodeObject($body);

            if ($payload === null || ! array_key_exists('status', $payload)) {
                return $this->indeterminate($httpStatus);
            }

            if ($payload['status'] === true) {
                return ProviderResult::accepted(
                    httpStatus: $httpStatus,
                    providerMessageId: $this->providerMessageId($payload),
                    metadata: array_filter([
                        'process' => is_scalar($payload['process'] ?? null) ? $payload['process'] : null,
                        'requestid' => is_scalar($payload['requestid'] ?? null) ? $payload['requestid'] : null,
                    ], static fn (mixed $value): bool => $value !== null),
                );
            }

            if (in_array($payload['status'], [false, 'false', 0, '0'], true)) {
                return ProviderResult::rejected(
                    httpStatus: $httpStatus,
                    errorCode: 'provider_rejected_message',
                    errorMessage: $this->reason($payload),
                );
            }

            return $this->indeterminate($httpStatus);
        }

        $payload = $this->decodeObject($body) ?? [];
        $message = $this->reason($payload);

        if ($httpStatus === 408 || ($httpStatus >= 300 && $httpStatus < 400) || $httpStatus >= 500) {
            return ProviderResult::outcomeUnknown(
                httpStatus: $httpStatus,
                errorCode: 'ambiguous_provider_http_error',
                errorMessage: 'Fonnte gagal setelah request mungkin sudah diproses.',
            );
        }

        if (in_array($httpStatus, [400, 422], true)) {
            return ProviderResult::rejected(
                httpStatus: $httpStatus,
                errorCode: 'invalid_message',
                errorMessage: $message,
            );
        }

        return ProviderResult::providerFailed(
            httpStatus: $httpStatus,
            errorCode: match ($httpStatus) {
                401, 403 => 'provider_authentication_failed',
                429 => 'provider_rate_limited',
                default => 'provider_unavailable',
            },
            errorMessage: $message,
        );
    }

    private function indeterminate(int $httpStatus): ProviderResult
    {
        return ProviderResult::outcomeUnknown(
            httpStatus: $httpStatus,
            errorCode: 'indeterminate_provider_response',
            errorMessage: 'Fonnte mengembalikan respons yang tidak dapat dipastikan.',
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
    private function providerMessageId(array $payload): ?string
    {
        $id = $payload['id'] ?? null;

        if (is_array($id)) {
            $id = $id[0] ?? null;
        }

        return is_scalar($id) && trim((string) $id) !== '' ? mb_substr((string) $id, 0, 255) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reason(array $payload): string
    {
        $reason = $payload['reason'] ?? $payload['detail'] ?? $payload['message'] ?? 'Fonnte menolak request.';

        return mb_substr(is_scalar($reason) ? (string) $reason : 'Fonnte menolak request.', 0, 500);
    }
}
