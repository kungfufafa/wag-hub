<?php

namespace App\Infrastructure\WhatsApp;

use App\Domain\Delivery\ProviderResult;

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

    private function sanitize(string $message): string
    {
        return mb_substr(trim($message), 0, 500);
    }
}
