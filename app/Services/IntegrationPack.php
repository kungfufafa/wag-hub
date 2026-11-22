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
            $this->abilities(),
        );

        return [
            'credential' => $credential,
            'env' => $this->envSnippet($application, $baseUrl ?? $this->hubUrl(), $credential->plainTextToken),
        ];
    }

    /**
     * Every ability a pack token carries: the v2 full-control preset plus the v1 abilities.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return array_values(array_unique(array_merge(config('engine_v2.scopes', []), ['messages:send', 'messages:read', 'numbers:check', 'engine:use'])));
    }

    /**
     * Oldest active credential that already holds every pack ability. Limited or
     * pre-v2 credentials never qualify, so they are not handed out as the pack token.
     */
    public function existingUsable(ClientApplication $application): ?ApiCredential
    {
        $required = $this->abilities();

        return $application->apiCredentials()
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->get()
            ->first(fn (ApiCredential $credential): bool => array_diff($required, $credential->abilities ?? []) === []);
    }

    /**
     * Reuse an existing full pack token when one is already issued. Plaintext cannot be
     * recovered, so the snippet names the stored prefix and asks for the saved token.
     * Otherwise a new token is issued and its plaintext is shown once.
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
                'env' => '# Token sudah pernah diterbitkan dan tidak bisa ditampilkan ulang; pakai WAG_TOKEN yang tersimpan (awalan '.$existing->token_prefix.').'
                    ."\n".$this->envSnippet(
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
            'WAG_URL='.rtrim($baseUrl, '/'),
            'WAG_TOKEN='.$token,
        ]);
    }

    public function hubUrl(): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/');
    }
}
