<?php

namespace App\Services\Alerts;

final class AlertErrorSummarizer
{
    public function summarize(?string $errorCode, ?string $errorMessage, int $max = 500): ?string
    {
        $parts = array_values(array_filter(
            [trim((string) $errorCode), trim((string) $errorMessage)],
            static fn (string $part): bool => $part !== '',
        ));

        if ($parts === []) {
            return null;
        }

        $summary = implode(': ', $parts);
        $summary = preg_replace('/\b(token|password)=[^&\s]*/i', '$1=[redacted]', $summary) ?? $summary;

        if (mb_strlen($summary) > $max) {
            $summary = mb_substr($summary, 0, $max);
        }

        return $summary;
    }
}
