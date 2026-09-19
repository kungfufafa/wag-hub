<?php

namespace App\Filament\Resources\ClientApplications\Pages;

use App\Filament\Pages\ConnectWhatsApp;
use App\Filament\Resources\ClientApplications\ClientApplicationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class EditClientApplication extends EditRecord
{
    protected static string $resource = ClientApplicationResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getSubheading(): ?string
    {
        return 'Perbarui identitas aplikasi, terbitkan kredensial, atau hapus aplikasi yang tidak dipakai.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connectWhatsApp')
                ->label('Hubungkan WhatsApp')
                ->icon(Heroicon::OutlinedSignal)
                ->url(fn (): string => ConnectWhatsApp::getUrl(['application' => $this->record->uuid])),
            ClientApplicationResource::createRoutingPolicyAction()
                ->label('Pengiriman & fallback'),
            ClientApplicationResource::deleteAction(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Aplikasi klien disimpan';
    }
}
