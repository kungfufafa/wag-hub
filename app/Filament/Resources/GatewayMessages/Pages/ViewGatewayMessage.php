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
        $asyncDispatch = app(GatewayMessageEnqueuer::class)->usesAsyncDispatch();

        return [
            Action::make('retry')
                ->label('Coba kirim ulang')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading($asyncDispatch
                    ? 'Masukkan pesan ini ke antrian ulang?'
                    : 'Kirim ulang pesan ini sekarang?')
                ->modalDescription(function () use ($asyncDispatch): string {
                    $risk = $this->getRecord()->status === 'outcome_unknown'
                        ? 'Hasil kirim sebelumnya tidak pasti. Riwayat tetap disimpan dan rute dievaluasi ulang. Ada risiko penerima menerima pesan ganda.'
                        : 'Riwayat percobaan tetap disimpan dan rute dievaluasi ulang.';

                    if ($asyncDispatch) {
                        return $risk;
                    }

                    return $risk.' Pengiriman dijalankan langsung (mode sync global).';
                })
                ->visible(fn (): bool => $this->getRecord()->isSafeToRetry())
                ->action(function () use ($asyncDispatch): void {
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

                    $enqueued = app(GatewayMessageEnqueuer::class)->enqueue($message, 'admin');
                    $this->getRecord()->refresh();
                    $status = (string) $this->getRecord()->status;

                    if (! $enqueued) {
                        Notification::make()
                            ->title($asyncDispatch
                                ? 'Kirim ulang disimpan, tetapi antrian tidak tersedia'
                                : 'Kirim ulang gagal diproses')
                            ->body($asyncDispatch
                                ? 'Pesan tetap berada di antrian dan pemulihan terjadwal akan mencoba lagi secara otomatis.'
                                : 'Status saat ini: '.$status.'. Periksa koneksi provider lalu coba lagi.')
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($asyncDispatch) {
                        Notification::make()
                            ->title('Kirim ulang masuk antrian')
                            ->success()
                            ->send();

                        return;
                    }

                    if ($status === 'provider_accepted') {
                        Notification::make()
                            ->title('Kirim ulang diproses')
                            ->success()
                            ->send();

                        return;
                    }

                    if ($status === 'outcome_unknown') {
                        Notification::make()
                            ->title('Kirim ulang selesai dengan status outcome_unknown')
                            ->body('Pengiriman langsung selesai, namun tidak dapat dipastikan apakah provider menerima pesan atau tidak.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Kirim ulang selesai dengan status '.$status)
                        ->body('Pengiriman langsung selesai, tetapi pesan belum diterima provider.')
                        ->danger()
                        ->send();
                }),
        ];
    }
}
