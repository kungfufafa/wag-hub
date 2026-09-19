<?php

namespace App\Services;

use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\IssuedApiCredential;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Str;

final readonly class IntegrationPack
{
    /** @return array{credential: IssuedApiCredential, env: string} */
    public function issue(ClientApplication $application, ?string $baseUrl = null): array
    {
        $credential = ApiCredential::issue(
            $application,
            'WAG_TOKEN '.(string) Str::uuid(),
            ['messages:send', 'messages:read', 'numbers:check', 'engine:use'],
        );

        return [
            'credential' => $credential,
            'env' => $this->envSnippet($application, $baseUrl ?? $this->hubUrl(), $credential->plainTextToken),
        ];
    }

    public function envSnippet(ClientApplication $application, string $baseUrl, string $token): string
    {
        $lines = [
            '# '.$application->slug,
            'WAG_URL='.rtrim($baseUrl, '/'),
            'WAG_TOKEN='.$token,
        ];

        $defaultConnection = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('is_default', true)
            ->value('uuid');

        if (is_string($defaultConnection) && $defaultConnection !== '') {
            $lines[] = 'WAG_CONNECTION_ID='.$defaultConnection;
        }

        return implode("\n", $lines);
    }

    public function hubUrl(): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/');
    }
}
