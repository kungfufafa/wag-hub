<?php

namespace App\Filament\Resources\WhatsAppConnections\Pages;

use App\Filament\Resources\WhatsAppConnections\WhatsAppConnectionResource;
use App\Filament\Support\CopiesToClipboard;
use App\Services\Connections\ConnectionHealthProjector;
use App\Services\Connections\ConnectionProvisioner;
use App\Services\Connections\ConnectionTestSender;
use App\Services\IntegrationPack;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Throwable;

class ViewWhatsAppConnection extends ViewRecord
{
    protected static string $resource = WhatsAppConnectionResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->refreshRecord();
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return collect([
            $record->clientApplication?->name,
            $record->typeEnum()->label(),
            $record->recommended_action ?: 'Siap dipakai',
        ])->filter()->implode(' · ');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Koneksi')
                ->schema([
                    TextEntry::make('name')->label('Nama'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (): string => $this->getRecord()->statusEnum()->color())
                        ->formatStateUsing(fn (): string => $this->getRecord()->statusEnum()->label()),
                    TextEntry::make('type')
                        ->label('Jenis')
                        ->formatStateUsing(fn (): string => $this->getRecord()->typeEnum()->label()),
                    TextEntry::make('health.operational_status')
                        ->label('Operasional')
                        ->state(fn (): string => app(ConnectionHealthProjector::class)->summarize($this->getRecord())['operational_status']),
                    TextEntry::make('recommended_action')
                        ->label('Langkah berikutnya')
                        ->placeholder('Tidak ada'),
                    TextEntry::make('capabilities')
                        ->label('Kemampuan')
                        ->formatStateUsing(fn (): string => implode(', ', $this->getRecord()->capabilities ?? [])),
                ])
                ->columns(2),
            Section::make('Kesehatan')
                ->schema([
                    TextEntry::make('last_success')
                        ->label('Kiriman terakhir berhasil')
                        ->state(fn (): string => app(ConnectionHealthProjector::class)->summarize($this->getRecord())['last_successful_send_at'] ?? 'Belum ada'),
                    TextEntry::make('failure_rate')
                        ->label('Rasio gagal terkini')
                        ->state(fn (): string => (string) app(ConnectionHealthProjector::class)->summarize($this->getRecord())['recent_failure_rate']),
                    TextEntry::make('sender')
                        ->label('Pengirim aktif')
                        ->state(function (): string {
                            $sender = app(ConnectionHealthProjector::class)->summarize($this->getRecord())['active_sender'] ?? null;

                            if (! is_array($sender)) {
                                return 'Belum dipilih';
                            }

                            return trim(($sender['name'] ?? '').' '.($sender['phone'] ?? ''));
                        }),
                ])
                ->columns(3),
            Section::make('Integrasi')
                ->schema([
                    TextEntry::make('integration')
                        ->hiddenLabel()
                        ->state(function (): HtmlString {
                            $application = $this->getRecord()->clientApplication;
                            $env = app(IntegrationPack::class)->envSnippet(
                                $application,
                                app(IntegrationPack::class)->hubUrl(),
                                'wgh_TOKEN_APLIKASI',
                            );

                            return new HtmlString(
                                '<pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100">'.e($env).'</pre>'.
                                '<p class="mt-2 text-sm text-gray-500">Terbitkan token dari aplikasi klien, lalu panggil wag.messages.send.</p>',
                            );
                        }),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconnect')
                ->label('Hubungkan ulang')
                ->icon(Heroicon::OutlinedQrCode)
                ->visible(fn (): bool => $this->getRecord()->providerAccount?->supportsSessions() ?? false)
                ->action(function (): void {
                    app(ConnectionProvisioner::class)->connect($this->getRecord());
                    $this->refreshRecord();
                    Notification::make()->title('Koneksi diperbarui')->success()->send();
                }),
            Action::make('test')
                ->label('Kirim pesan uji')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->form([
                    TextInput::make('recipient')
                        ->label('Nomor tujuan')
                        ->required(),
                    TextInput::make('text')
                        ->label('Isi pesan')
                        ->default('Tes koneksi WAG Hub')
                        ->required(),
                ])
                ->visible(fn (): bool => $this->getRecord()->canAttemptSend())
                ->action(function (array $data): void {
                    $connection = $this->getRecord();

                    if (! $connection->canAttemptSend()) {
                        Notification::make()->title('Koneksi belum siap untuk uji kirim')->danger()->send();

                        return;
                    }

                    try {
                        $response = app(ConnectionTestSender::class)->send(
                            $connection,
                            (string) $data['recipient'],
                            (string) $data['text'],
                        );
                        $payload = $response->getData(true);
                        $success = ($payload['data']['status'] ?? null) === 'provider_accepted';
                        Notification::make()
                            ->title($success ? 'Uji kirim berhasil' : 'Uji kirim gagal')
                            ->body($success
                                ? 'Provider menerima pesan melalui POST /api/v1/messages.'
                                : (string) ($payload['message'] ?? 'Provider menolak atau gagal menerima pesan.'))
                            ->{$success ? 'success' : 'danger'}()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()->title('Uji kirim gagal')->body($exception->getMessage())->danger()->send();
                    }

                    $this->refreshRecord();
                }),
            Action::make('copyEnv')
                ->label('Salin WAG_URL')
                ->icon(Heroicon::OutlinedClipboard)
                ->extraAttributes(fn (): array => [
                    'x-on:click' => CopiesToClipboard::alpine(
                        app(IntegrationPack::class)->hubUrl(),
                        'WAG_URL disalin.',
                    ),
                ]),
        ];
    }

    private function refreshRecord(): void
    {
        $this->record = WhatsAppConnectionResource::refresh($this->getRecord());
    }
}
