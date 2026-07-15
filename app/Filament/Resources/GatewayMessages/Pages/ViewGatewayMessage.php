<?php

namespace App\Filament\Resources\GatewayMessages\Pages;

use App\Filament\Resources\GatewayMessages\GatewayMessageResource;
use App\Jobs\DispatchGatewayMessage;
use App\Models\GatewayMessage;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class ViewGatewayMessage extends ViewRecord
{
    protected static string $resource = GatewayMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label('Retry message')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Queue this message for retry?')
                ->modalDescription('The existing attempt history is retained and routing is evaluated again.')
                ->visible(fn (): bool => $this->getRecord()->isSafeToRetry())
                ->action(function (): void {
                    try {
                        DB::transaction(function (): void {
                            /** @var GatewayMessage $message */
                            $message = GatewayMessage::query()->findOrFail($this->getRecord()->getKey());
                            $message->queueForRetry();
                            $message->events()->create([
                                'type' => 'manual_retry_queued',
                                'source' => 'admin',
                                'data' => [
                                    'administrator_id' => auth()->id(),
                                ],
                                'occurred_at' => now(),
                            ]);

                            DispatchGatewayMessage::dispatch($message->getKey())->afterCommit();
                        });
                    } catch (DomainException) {
                        Notification::make()
                            ->title('Retry no longer available')
                            ->body('The message state changed or it has expired. Refresh and inspect the latest timeline.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->getRecord()->refresh();

                    Notification::make()
                        ->title('Retry queued')
                        ->success()
                        ->send();
                }),
        ];
    }
}
