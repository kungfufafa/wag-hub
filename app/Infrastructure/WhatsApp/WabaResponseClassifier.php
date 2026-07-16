<?php

namespace App\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderResult;

final class WabaResponseClassifier
{
    public function classify(int $httpStatus, string $body): ProviderResult
    {
        $payload = $this->decodeObject($body);

        if ($httpStatus >= 200 && $httpStatus < 300) {
            $messageId = data_get($payload, 'messages.0.id');

            if (! is_scalar($messageId) || trim((string) $messageId) === '') {
                return ProviderResult::outcomeUnknown(
                    httpStatus: $httpStatus,
                    errorCode: 'indeterminate_provider_response',
                    errorMessage: 'WABA mengembalikan respons yang tidak dapat dipastikan.',
                );
            }

            return ProviderResult::accepted(
                httpStatus: $httpStatus,
                providerMessageId: mb_substr(trim((string) $messageId), 0, 255),
                metadata: array_filter([
                    'wa_id' => is_scalar(data_get($payload, 'contacts.0.wa_id'))
                        ? data_get($payload, 'contacts.0.wa_id')
                        : null,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        $message = $this->message($payload);
        $errorCode = data_get($payload, 'error.code');

        if ($httpStatus === 408 || ($httpStatus >= 300 && $httpStatus < 400) || $httpStatus >= 500) {
            return ProviderResult::outcomeUnknown(
                httpStatus: $httpStatus,
                errorCode: 'ambiguous_provider_http_error',
                errorMessage: 'WABA gagal setelah request mungkin sudah diproses.',
            );
        }

        if ($httpStatus === 401 || $httpStatus === 403 || (int) $errorCode === 190) {
            return ProviderResult::providerFailed(
                httpStatus: $httpStatus,
                errorCode: 'provider_authentication_failed',
                errorMessage: $message,
            );
        }

        if ($httpStatus === 429 || in_array((int) $errorCode, [4, 17, 80007, 130429], true)) {
            return ProviderResult::providerFailed(
                httpStatus: $httpStatus,
                errorCode: 'provider_rate_limited',
                errorMessage: $message,
            );
        }

        if (in_array($httpStatus, [400, 422], true)) {
            return ProviderResult::rejected(
                httpStatus: $httpStatus,
                errorCode: 'provider_rejected_message',
                errorMessage: $message,
            );
        }

        return ProviderResult::providerFailed(
            httpStatus: $httpStatus,
            errorCode: 'provider_unavailable',
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
        $message = data_get($payload, 'error.message') ?? 'WABA menolak request.';

        return mb_substr(is_scalar($message) ? (string) $message : 'WABA menolak request.', 0, 500);
    }
}
