<?php

namespace App\Filament\Resources\GatewayMessages\Pages;

use App\Filament\Resources\GatewayMessages\GatewayMessageResource;
use App\Models\GatewayMessage;
use App\Services\GatewayMessageEnqueuer;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class ViewGatewayMessage extends ViewRecord
{
    protected static string $resource = GatewayMessageResource::class;

    public function getTitle(): string
    {
        return 'Detail pesan';
    }

    public function getHeading(): string
    {
        return 'Detail pesan';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label('Coba kirim ulang')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Masukkan pesan ini ke antrian ulang?')
                ->modalDescription(fn (): string => $this->getRecord()->status === 'outcome_unknown'
                    ? 'Hasil kirim sebelumnya tidak pasti. Riwayat tetap disimpan dan rute dievaluasi ulang. Ada risiko penerima menerima pesan ganda.'
                    : 'Riwayat percobaan tetap disimpan dan rute dievaluasi ulang.')
                ->visible(fn (): bool => $this->getRecord()->isSafeToRetry())
                ->action(function (): void {
                    try {
                        $message = DB::transaction(function (): GatewayMessage {
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

                            return $message;
                        });
                    } catch (DomainException) {
                        Notification::make()
                            ->title('Kirim ulang tidak lagi tersedia')
                            ->body('Status pesan berubah atau sudah kedaluwarsa. Muat ulang dan periksa linimasa terbaru.')
                            ->danger()
                            ->send();

                        return;
                    }

                    if (! app(GatewayMessageEnqueuer::class)->enqueue($message, 'admin')) {
                        $this->getRecord()->refresh();

                        Notification::make()
                            ->title('Kirim ulang disimpan, tetapi antrian tidak tersedia')
                            ->body('Pesan tetap berada di antrian dan pemulihan terjadwal akan mencoba lagi secara otomatis.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->getRecord()->refresh();

                    Notification::make()
                        ->title('Kirim ulang masuk antrian')
                        ->success()
                        ->send();
                }),
        ];
    }
}
