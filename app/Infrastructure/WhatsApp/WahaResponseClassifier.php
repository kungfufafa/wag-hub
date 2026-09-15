<?php

namespace App\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderResult;

final class WahaResponseClassifier
{
    public function classify(int $httpStatus, string $body): ProviderResult
    {
        if ($httpStatus >= 200 && $httpStatus < 300) {
            if ($httpStatus === 201 && trim($body) === '') {
                return ProviderResult::accepted(httpStatus: $httpStatus);
            }

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

        if ($httpStatus === 408 || ($httpStatus >= 300 && $httpStatus < 400)) {
            $baseMessage = 'WAHA gagal setelah request mungkin sudah diproses (Timeout/Redirect).';

            return ProviderResult::outcomeUnknown(
                httpStatus: $httpStatus,
                errorCode: 'ambiguous_provider_http_error',
                errorMessage: $message !== 'WAHA menolak request.' ? $baseMessage.' Respons: '.$message : $baseMessage,
            );
        }

        if ($httpStatus >= 500) {
            $baseMessage = 'WAHA mengalami internal server error.';

            return ProviderResult::providerFailed(
                httpStatus: $httpStatus,
                errorCode: 'provider_server_error',
                errorMessage: $message !== 'WAHA menolak request.' ? $baseMessage.' Respons: '.$message : $baseMessage,
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

        // WAHA engines vary: WEBJS often uses success/sent; NOWEB/GOWS commonly return PENDING.
        return in_array($status, [
            'success',
            'sent',
            'ok',
            'pending',
            'server_ack',
            'device',
            'read',
            'played',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerMessageId(array $payload): ?string
    {
        $candidates = [
            $payload['id'] ?? null,
            data_get($payload, '_data.id'),
            data_get($payload, 'key.id'),
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->stringifyMessageId($candidate);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->compositeKeyMessageId($payload['key'] ?? null);
    }

    private function stringifyMessageId(mixed $id): ?string
    {
        if (is_scalar($id) && trim((string) $id) !== '') {
            return mb_substr((string) $id, 0, 255);
        }

        if (! is_array($id) || $id === []) {
            return null;
        }

        foreach (['_serialized', 'id', '_serialized_id'] as $key) {
            $value = $id[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr((string) $value, 0, 255);
            }
        }

        return null;
    }

    private function compositeKeyMessageId(mixed $key): ?string
    {
        if (! is_array($key)) {
            return null;
        }

        $messageId = $this->stringifyMessageId($key['id'] ?? null);
        $remoteJid = is_scalar($key['remoteJid'] ?? null) ? trim((string) $key['remoteJid']) : '';

        if ($messageId === null || $remoteJid === '') {
            return null;
        }

        $fromMe = filter_var($key['fromMe'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        $parts = [$fromMe, $remoteJid, $messageId];
        $participant = is_scalar($key['participant'] ?? null) ? trim((string) $key['participant']) : '';

        if ($participant !== '') {
            $parts[] = $participant;
        }

        return mb_substr(implode('_', $parts), 0, 255);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeMetadata(array $payload): array
    {
        return array_filter([
            'status' => is_scalar($payload['status'] ?? null) ? $payload['status'] : null,
            'timestamp' => is_scalar($payload['timestamp'] ?? null)
                ? $payload['timestamp']
                : (is_scalar($payload['messageTimestamp'] ?? null) ? $payload['messageTimestamp'] : null),
            'ack' => is_scalar($payload['ack'] ?? null) ? $payload['ack'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function errorMessage(string $body): string
    {
        $payload = json_decode($body, true);

        if (is_array($payload)) {
            $message = $payload['error'] ?? $payload['message'] ?? null;
            if (is_scalar($message) && trim((string) $message) !== '') {
                return mb_substr(trim((string) $message), 0, 500);
            }
        }

        $trimmedBody = trim($body);
        if ($trimmedBody !== '') {
            return mb_substr($trimmedBody, 0, 500);
        }

        return 'WAHA menolak request.';
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
