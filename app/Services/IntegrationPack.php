<?php

namespace App\Services;

use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\IssuedApiCredential;
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

    public function existingUsable(ClientApplication $application): ?ApiCredential
    {
        return $application->apiCredentials()
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * Reuse an existing token when one is already issued. Plaintext cannot be
     * recovered, so the snippet shows the stored prefix instead of minting again.
     *
     * @return array{issued: bool, credential: IssuedApiCredential|null, env: string}
     */
    public function copyOrIssue(ClientApplication $application, ?string $baseUrl = null): array
    {
        $existing = $this->existingUsable($application);

        if ($existing !== null) {
            return [
                'issued' => false,
                'credential' => null,
                'env' => $this->envSnippet(
                    $application,
                    $baseUrl ?? $this->hubUrl(),
                    $existing->token_prefix.'…',
                ),
            ];
        }

        $issued = $this->issue($application, $baseUrl);

        return [
            'issued' => true,
            'credential' => $issued['credential'],
            'env' => $issued['env'],
        ];
    }

    public function envSnippet(ClientApplication $application, string $baseUrl, string $token): string
    {
        return implode("\n", [
            '# '.$application->slug,
            'WAG_URL='.rtrim($baseUrl, '/'),
            'WAG_TOKEN='.$token,
        ]);
    }

    public function hubUrl(): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/');
    }
}
