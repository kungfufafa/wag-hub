<?php

namespace App\Filament\Resources\WhatsAppConnections\Pages;

use App\Filament\Pages\ConnectWhatsApp;
use App\Filament\Resources\WhatsAppConnections\WhatsAppConnectionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListWhatsAppConnections extends ListRecords
{
    protected static string $resource = WhatsAppConnectionResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getSubheading(): ?string
    {
        return 'Satu koneksi WhatsApp per kemampuan kirim. Detail provider dan rute ada di pengaturan lanjutan.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Hubungkan WhatsApp')
                ->icon(Heroicon::OutlinedPlus)
                ->url(ConnectWhatsApp::getUrl()),
        ];
    }
}
