<?php

namespace App\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderResult;

final class GowaResponseClassifier
{
    public function classify(int $httpStatus, string $body): ProviderResult
    {
        $payload = $this->decodeObject($body);

        if ($httpStatus >= 200 && $httpStatus < 300) {
            $messageId = data_get($payload, 'results.message_id');

            if (
                strtoupper(trim((string) ($payload['code'] ?? ''))) !== 'SUCCESS'
                || ! is_scalar($messageId)
                || trim((string) $messageId) === ''
            ) {
                return ProviderResult::outcomeUnknown(
                    httpStatus: $httpStatus,
                    errorCode: 'indeterminate_provider_response',
                    errorMessage: 'GOWA mengembalikan respons yang tidak dapat dipastikan.',
                );
            }

            return ProviderResult::accepted(
                httpStatus: $httpStatus,
                providerMessageId: mb_substr(trim((string) $messageId), 0, 255),
                metadata: array_filter([
                    'status' => is_scalar(data_get($payload, 'results.status'))
                        ? data_get($payload, 'results.status')
                        : null,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        $message = $this->message($payload);

        if ($httpStatus === 408 || ($httpStatus >= 300 && $httpStatus < 400) || $httpStatus >= 500) {
            return ProviderResult::outcomeUnknown(
                httpStatus: $httpStatus,
                errorCode: 'ambiguous_provider_http_error',
                errorMessage: 'GOWA gagal setelah request mungkin sudah diproses.',
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

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $body): array
    {
        $payload = json_decode($body, true);

        return is_array($payload) && ! array_is_list($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function message(array $payload): string
    {
        $message = $payload['message'] ?? data_get($payload, 'error.message') ?? 'GOWA menolak request.';

        return mb_substr(is_scalar($message) ? (string) $message : 'GOWA menolak request.', 0, 500);
    }
}
