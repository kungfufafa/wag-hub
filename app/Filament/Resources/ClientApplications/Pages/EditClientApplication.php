<?php

namespace App\Filament\Resources\ClientApplications\Pages;

use App\Filament\Resources\ClientApplications\ClientApplicationResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

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
            ClientApplicationResource::createRoutingPolicyAction(),
            ClientApplicationResource::deleteAction(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Aplikasi klien disimpan';
    }
}
