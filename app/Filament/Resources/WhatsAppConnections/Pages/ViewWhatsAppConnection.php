<?php

namespace App\Filament\Resources\WhatsAppConnections\Pages;

use App\Filament\Resources\WhatsAppConnections\WhatsAppConnectionResource;
use App\Models\WhatsAppConnection;
use App\Services\Connection\ConnectionProvisioner;
use App\Services\IntegrationPack;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ViewWhatsAppConnection extends ViewRecord
{
    protected static string $resource = WhatsAppConnectionResource::class;

    public function infolist(Schema $schema): Schema
    {
        /** @var WhatsAppConnection $record */
        $record = $this->getRecord();
        $presented = WhatsAppConnectionResource::presentRecord($record);

        return $schema->components([
            Section::make('Ringkasan koneksi')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label('Nama')->state($presented['name']),
                    TextEntry::make('status')->label('Status')->state($presented['status'])->badge(),
                    TextEntry::make('type')->label('Tipe')->state($presented['type']),
                    TextEntry::make('application')->label('Aplikasi')->state($presented['application']),
                    TextEntry::make('next_action')->label('Tindakan berikutnya')->state($presented['next_action'] ?? '—'),
                    TextEntry::make('status_detail')->label('Detail')->state($presented['status_detail'] ?? '—')->columnSpanFull(),
                ]),
            Section::make('Kemampuan')
                ->schema([
                    TextEntry::make('capabilities')
                        ->label('Capabilities')
                        ->state(implode(', ', $presented['capabilities'] ?? []))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setDefault')
                ->label('Jadikan default')
                ->icon(Heroicon::Star)
                ->visible(fn (WhatsAppConnection $record): bool => ! $record->is_default)
                ->action(function (WhatsAppConnection $record): void {
                    app(ConnectionProvisioner::class)->setDefault($record);
                    $this->refreshFormData(['is_default']);
                }),
            Action::make('integration')
                ->label('Salin konfigurasi integrasi')
                ->icon(Heroicon::ClipboardDocumentList)
                ->modalHeading('Konfigurasi integrasi')
                ->schema([
                    Textarea::make('env')
                        ->label('Environment')
                        ->rows(4)
                        ->readOnly()
                        ->default(function (WhatsAppConnection $record): string {
                            $pack = app(IntegrationPack::class);

                            return implode("\n", [
                                'WAG_URL='.$pack->hubUrl(),
                                'WAG_TOKEN=<token-aplikasi>',
                                'WAG_CONNECTION_ID='.(string) $record->uuid,
                            ]);
                        })
                        ->extraInputAttributes(['class' => 'font-mono text-xs']),
                    Placeholder::make('hint')
                        ->content(new HtmlString('Kirim pesan dengan <code>connection_id</code> atau header <code>X-WAG-Use-Default-Connection: true</code>.')),
                ])
                ->modalSubmitAction(false),
        ];
    }
}
