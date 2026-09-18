<?php

namespace App\Services;

use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\IssuedApiCredential;

/**
 * One-click Hub + Engine credentials and a copy-paste env block so IT
 * does not assemble tokens or mix the fallback lane with user-linked numbers.
 */
final readonly class IntegrationPack
{
    /**
     * @return array{hub: IssuedApiCredential, engine: IssuedApiCredential, env: string}
     */
    public function issue(ClientApplication $application, ?string $baseUrl = null): array
    {
        $hub = ApiCredential::issue(
            $application,
            $this->uniqueName($application, 'Paket Hub'),
            $this->hubAbilities($application),
        );
        $engine = ApiCredential::issue(
            $application,
            $this->uniqueName($application, 'Paket Engine'),
            ['engine:use'],
        );

        return [
            'hub' => $hub,
            'engine' => $engine,
            'env' => $this->envSnippet(
                $application,
                $baseUrl ?? $this->hubUrl(),
                $hub->plainTextToken,
                $engine->plainTextToken,
            ),
        ];
    }

    public function envSnippet(
        ClientApplication $application,
        string $baseUrl,
        string $hubToken,
        string $engineToken,
    ): string {
        $baseUrl = rtrim($baseUrl, '/');
        $engineUrl = $baseUrl.'/engine/t/'.$engineToken;
        $slug = (string) $application->slug;

        $lines = [
            '# Tempel di aplikasi sumber. User bisnis tidak perlu melihat token ini.',
            'WAG_URL='.$baseUrl,
            'WAG_TOKEN='.$hubToken,
        ];

        if ($slug === 'web-cesa') {
            $lines[] = 'REKRUTMEN_WHATSAPP_ENGINE_URL='.$engineUrl;
            $lines[] = 'REKRUTMEN_WHATSAPP_ENGINE_AUTO_START=false';
        } else {
            $lines[] = 'ENGINE_URL='.$engineUrl;
        }

        $lines[] = '';
        $lines[] = '# WAG_TOKEN = OTP/notifikasi (fallback). ENGINE = nomor yang user scan sendiri.';

        return implode("\n", $lines);
    }

    public function hubUrl(): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/');
    }

    /**
     * @return list<string>
     */
    public function hubAbilities(ClientApplication $application): array
    {
        $abilities = ['messages:send', 'messages:read'];

        if ((string) $application->slug === 'web-cesa') {
            $abilities[] = 'numbers:check';
        }

        return $abilities;
    }

    private function uniqueName(ClientApplication $application, string $base): string
    {
        $exists = ApiCredential::query()
            ->where('client_application_id', $application->getKey())
            ->where('name', $base)
            ->exists();

        return $exists ? $base.' '.now()->format('Ymd-His') : $base;
    }
}
