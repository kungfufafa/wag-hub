<?php

namespace App\Filament\Resources\RoutingPolicies\Pages;

use App\Filament\Resources\RoutingPolicies\RoutingPolicyResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateRoutingPolicy extends CreateRecord
{
    protected static string $resource = RoutingPolicyResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['operation'] ?? 'message') === 'number_check') {
            $data['purpose'] = null;
        }

        if (blank($data['key'] ?? null)) {
            $data['key'] = 'default';
        }

        return $data;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Aturan rute dibuat')
            ->body('Provider akan dicoba dari atas ke bawah sampai mendapat hasil definitif.');
    }
}
