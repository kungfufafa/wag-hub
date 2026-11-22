<?php

namespace App\Filament\Resources\GatewayMessages\Pages;

use App\Filament\Resources\GatewayMessages\GatewayMessageResource;
use App\Filament\Support\CopiesToClipboard;
use App\Models\GatewayMessage;
use App\Services\GatewayMessageEnqueuer;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class ViewGatewayMessage extends ViewRecord
{
    protected static string $resource = GatewayMessageResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getTitle(): string
    {
        return GatewayMessageResource::statusLabel($this->getRecord()->status);
    }

    public function getHeading(): string
    {
        return GatewayMessageResource::statusLabel($this->getRecord()->status);
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();
        $application = $record->clientApplication?->name ?? 'Aplikasi tidak diketahui';
        $purpose = GatewayMessageResource::purposeLabel($record->purpose);
        $createdAt = $record->created_at?->timezone(config('app.timezone'))->translatedFormat('d M Y H.i');

        return collect([$application, $purpose, $createdAt])->filter()->implode(' · ');
    }

    public function getBreadcrumb(): string
    {
        return 'Detail';
    }

    protected function getHeaderActions(): array
    {
        $asyncDispatch = app(GatewayMessageEnqueuer::class)->usesAsyncDispatch();

        return [
            Action::make('copyBody')
                ->label('Salin isi pesan')
                ->icon(Heroicon::OutlinedClipboardDocument)
                ->color('gray')
                ->visible(fn (): bool => filled($this->getRecord()->plaintextBody()))
                ->alpineClickHandler(fn (): string => CopiesToClipboard::alpine($this->getRecord()->plaintextBody())),
            Action::make('retry')
                ->label('Coba kirim ulang')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading($asyncDispatch
                    ? 'Masukkan pesan ini ke antrian ulang?'
                    : 'Kirim ulang pesan ini sekarang?')
                ->modalDescription(fn (): ?string => $this->getRecord()->status === 'outcome_unknown'
                    ? 'Penerima bisa menerima pesan ganda.'
                    : null)
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
                            ->body('Status pesan sudah berubah.')
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
                                ? 'Akan dicoba lagi otomatis.'
                                : 'Status saat ini: '.$status.'.')
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
                            ->body('Hasil pengiriman tidak pasti.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Kirim ulang selesai dengan status '.$status)
                        ->body('Pesan belum diterima provider.')
                        ->danger()
                        ->send();
                }),
        ];
    }
}
