<?php

namespace App\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderResult;
use Throwable;

final class TransportFailureClassifier
{
    public function classify(
        string $errorCode,
        string $errorMessage,
        bool $requestMayHaveBeenSent,
    ): ProviderResult {
        if (! $requestMayHaveBeenSent) {
            return ProviderResult::providerFailed(
                errorCode: $errorCode,
                errorMessage: $this->sanitize($errorMessage),
            );
        }

        return ProviderResult::outcomeUnknown(
            errorCode: $errorCode,
            errorMessage: $this->sanitize($errorMessage),
        );
    }

    public function classifyException(Throwable $exception): ProviderResult
    {
        $message = strtolower($exception->getMessage());
        $definitivelyNotSent = $this->containsAny($message, [
            'curl error 6',
            'curl error 7',
            'could not resolve host',
            'connection refused',
            'failed to connect',
            'name or service not known',
        ]);

        if ($definitivelyNotSent) {
            return $this->classify(
                errorCode: 'provider_connection_failed',
                errorMessage: 'Koneksi ke provider gagal sebelum request terkirim.',
                requestMayHaveBeenSent: false,
            );
        }

        return $this->classify(
            errorCode: str_contains($message, 'timed out') || str_contains($message, 'timeout')
                ? 'transport_timeout'
                : 'transport_interrupted',
            errorMessage: 'Koneksi provider terputus setelah request mungkin terkirim.',
            requestMayHaveBeenSent: true,
        );
    }

    private function sanitize(string $message): string
    {
        return mb_substr(trim($message), 0, 500);
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
