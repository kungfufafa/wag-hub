<?php

namespace App\Filament\Resources\ProviderAccounts\Pages;

use App\Filament\Resources\ProviderAccounts\ProviderAccountResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditProviderAccount extends EditRecord
{
    protected static string $resource = ProviderAccountResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $configuration = $this->record->configuration ?? [];

        $data['configuration'] = Arr::except($configuration, ['api_key', 'token']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $driver = $data['driver'];
        $allowedKeys = $driver === 'waha'
            ? ['base_url', 'session', 'api_key']
            : ['endpoint', 'token'];
        $existing = $this->record->driver === $driver
            ? Arr::only($this->record->configuration ?? [], $allowedKeys)
            : [];
        $incoming = array_filter(
            Arr::only($data['configuration'] ?? [], $allowedKeys),
            fn ($value): bool => filled($value),
        );
        $configuration = array_replace($existing, $incoming);
        $secretKey = $driver === 'waha' ? 'api_key' : 'token';

        if (blank($configuration[$secretKey] ?? null)) {
            throw ValidationException::withMessages([
                "data.configuration.{$secretKey}" => 'A provider secret is required when changing drivers.',
            ]);
        }

        $data['configuration'] = $configuration;

        return $data;
    }
}
