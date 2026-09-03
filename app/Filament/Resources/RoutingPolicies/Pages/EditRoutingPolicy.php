<?php

namespace App\Filament\Resources\RoutingPolicies\Pages;

use App\Filament\Resources\RoutingPolicies\RoutingPolicyResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditRoutingPolicy extends EditRecord
{
    protected static string $resource = RoutingPolicyResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getSubheading(): ?string
    {
        return 'Ubah cakupan rute, urutan cadangan, atau hapus aturan yang tidak dipakai.';
    }

    protected function getHeaderActions(): array
    {
        return [
            RoutingPolicyResource::deleteAction(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Aturan rute disimpan';
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['operation'] ?? 'message') === 'number_check') {
            $data['purpose'] = null;
        }

        return $data;
    }
}
