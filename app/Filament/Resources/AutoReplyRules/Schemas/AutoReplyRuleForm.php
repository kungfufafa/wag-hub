<?php

namespace App\Filament\Resources\AutoReplyRules\Schemas;

use App\Models\ProviderAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AutoReplyRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Aturan balasan otomatis')
                    ->schema([
                        Select::make('provider_account_id')
                            ->label('Akun provider (nomor)')
                            ->options(fn (): array => ProviderAccount::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->native(false),
                        TextInput::make('name')
                            ->label('Nama aturan')
                            ->required()
                            ->maxLength(120),
                        Select::make('match_type')
                            ->label('Pemicu')
                            ->options([
                                'welcome' => 'Sapaan (kontak pertama kali)',
                                'keyword' => 'Kata kunci',
                                'fallback' => 'Cadangan (jika tidak ada yang cocok)',
                            ])
                            ->default('keyword')
                            ->required()
                            ->live()
                            ->native(false),
                        Select::make('match_mode')
                            ->label('Kecocokan kata kunci')
                            ->options([
                                'contains' => 'Mengandung',
                                'exact' => 'Sama persis',
                            ])
                            ->default('contains')
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('match_type') === 'keyword')
                            ->required(fn (Get $get): bool => $get('match_type') === 'keyword'),
                        TagsInput::make('keywords')
                            ->label('Kata kunci')
                            ->visible(fn (Get $get): bool => $get('match_type') === 'keyword')
                            ->required(fn (Get $get): bool => $get('match_type') === 'keyword')
                            ->columnSpanFull(),
                        Textarea::make('reply_body')
                            ->label('Isi balasan')
                            ->rows(5)
                            ->required()
                            ->maxLength(4096)
                            ->columnSpanFull(),
                        TextInput::make('priority')
                            ->label('Prioritas')
                            ->numeric()
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->required(),
                    ])
                    ->columns(['md' => 2]),
            ]);
    }
}
