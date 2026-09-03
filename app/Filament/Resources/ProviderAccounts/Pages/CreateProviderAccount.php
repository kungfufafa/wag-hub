<?php

namespace App\Filament\Resources\ProviderAccounts\Pages;

use App\Filament\Resources\ProviderAccounts\ProviderAccountResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateProviderAccount extends CreateRecord
{
    protected static string $resource = ProviderAccountResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['health_status'] = 'unknown';
        $data['consecutive_failures'] = 0;

        return $data;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Akun provider dibuat')
            ->body('Uji koneksi dari tombol Uji, lalu masukkan akun ini ke aturan rute.');
    }
}
