<?php

namespace App\Filament\Resources\ClientApplications\Pages;

use App\Filament\Pages\ConnectWhatsApp;
use App\Filament\Resources\ClientApplications\ClientApplicationResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateClientApplication extends CreateRecord
{
    protected static string $resource = ClientApplicationResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Aplikasi klien dibuat')
            ->body('Lanjutkan dengan Hubungkan WhatsApp, kirim uji, lalu salin konfigurasi.');
    }

    protected function getRedirectUrl(): string
    {
        return ConnectWhatsApp::getUrl(['application' => $this->record->uuid]);
    }
}
