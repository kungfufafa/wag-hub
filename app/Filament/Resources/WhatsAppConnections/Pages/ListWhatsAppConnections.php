<?php

namespace App\Filament\Resources\WhatsAppConnections\Pages;

use App\Filament\Resources\WhatsAppConnections\WhatsAppConnectionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListWhatsAppConnections extends ListRecords
{
    protected static string $resource = WhatsAppConnectionResource::class;

    public function getSubheading(): ?string
    {
        return 'Kelola koneksi WhatsApp per aplikasi. Gunakan wizard Hubungkan WhatsApp untuk onboarding cepat.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Hubungkan WhatsApp')
                ->icon(Heroicon::OutlinedQrCode)
                ->url(ConnectWhatsApp::getUrl()),
        ];
    }
}
