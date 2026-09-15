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

    public function getSubheading(): ?string
    {
        return 'Perbarui koneksi, uji akun, atau hapus provider yang tidak dipakai.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ProviderAccountResource::testAction(),
            ProviderAccountResource::deleteAction(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Akun provider disimpan';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $configuration = $this->record->configuration ?? [];

        $data['configuration'] = Arr::except($configuration, [
            'api_key',
            'token',
            'password',
            'access_token',
            'webhook_secret',
        ]);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $driver = $data['driver'];
        $allowedKeys = match ($driver) {
            'waha' => ['base_url', 'session', 'api_key', 'webhook_secret'],
            'fonnte' => ['endpoint', 'validate_endpoint', 'token', 'attachment_max_bytes', 'webhook_secret'],
            'gowa' => ['base_url', 'username', 'password', 'device_id', 'version', 'webhook_secret'],
            'waba' => ['base_url', 'api_version', 'phone_number_id', 'access_token', 'webhook_secret'],
            default => [],
        };
        $existing = $this->record->driver === $driver
            ? Arr::only($this->record->configuration ?? [], $allowedKeys)
            : [];
        $incoming = array_filter(
            Arr::only($data['configuration'] ?? [], $allowedKeys),
            fn ($value): bool => filled($value),
        );
        $configuration = array_replace($existing, $incoming);
        $secretKey = match ($driver) {
            'waha' => 'api_key',
            'fonnte' => 'token',
            'gowa' => 'password',
            'waba' => 'access_token',
            default => null,
        };

        if ($secretKey === null || blank($configuration[$secretKey] ?? null)) {
            throw ValidationException::withMessages([
                'data.driver' => 'Driver provider tidak didukung atau secret belum diisi.',
            ]);
        }

        $data['configuration'] = $configuration;

        return $data;
    }
}
