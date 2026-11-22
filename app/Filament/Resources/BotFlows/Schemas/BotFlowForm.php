<?php

namespace App\Filament\Resources\BotFlows\Schemas;

use App\Models\ProviderAccount;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BotFlowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Menu bot')
                    ->schema([
                        Select::make('provider_account_id')
                            ->label('Akun provider (nomor)')
                            ->options(fn (): array => ProviderAccount::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->native(false),
                        TextInput::make('name')
                            ->label('Nama menu')
                            ->required()
                            ->maxLength(120),
                        Select::make('trigger_type')
                            ->label('Pemicu')
                            ->options([
                                'welcome' => 'Sapaan (kontak pertama kali)',
                                'keyword' => 'Kata kunci',
                            ])
                            ->default('keyword')
                            ->required()
                            ->live()
                            ->native(false),
                        TagsInput::make('keywords')
                            ->label('Kata kunci pemicu')
                            ->visible(fn (Get $get): bool => $get('trigger_type') === 'keyword')
                            ->required(fn (Get $get): bool => $get('trigger_type') === 'keyword'),
                        TextInput::make('priority')
                            ->label('Prioritas')
                            ->numeric()
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->required(),
                        TextInput::make('session_ttl_minutes')
                            ->label('Kedaluwarsa sesi (menit)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->default(10)
                            ->required(),
                    ])
                    ->columns(['md' => 2]),
                Section::make('Isi menu')
                    ->schema([
                        Textarea::make('header')
                            ->label('Teks pembuka')
                            ->rows(3)
                            ->required()
                            ->columnSpanFull(),
                        Repeater::make('options')
                            ->label('Pilihan menu')
                            ->schema([
                                TextInput::make('key')
                                    ->label('Kunci')
                                    ->required()
                                    ->maxLength(12),
                                TextInput::make('label')
                                    ->label('Judul pilihan')
                                    ->required()
                                    ->maxLength(120),
                                Select::make('action')
                                    ->label('Aksi')
                                    ->options([
                                        'reply' => 'Balas teks',
                                        'handoff' => 'Serahkan ke agen',
                                    ])
                                    ->default('reply')
                                    ->required()
                                    ->native(false)
                                    ->live(),
                                Textarea::make('reply')
                                    ->label(fn (Get $get): string => $get('action') === 'handoff' ? 'Pesan sebelum diserahkan' : 'Isi balasan')
                                    ->rows(3)
                                    ->required(fn (Get $get): bool => $get('action') === 'reply'),
                            ])
                            ->columns(['md' => 3])
                            ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null) ? ($state['key'] ?? '').'. '.$state['label'] : null)
                            ->reorderable()
                            ->collapsible()
                            ->minItems(1)
                            ->defaultItems(2)
                            ->columnSpanFull(),
                        Textarea::make('footer')
                            ->label('Teks penutup (opsional)')
                            ->rows(2)
                            ->placeholder('Ketik angka pilihan Anda.')
                            ->columnSpanFull(),
                        Textarea::make('fallback_reply')
                            ->label('Balasan bila pilihan tidak dikenali (opsional)')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
