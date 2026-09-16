<?php

namespace App\Filament\Resources\KnowledgeBaseEntries\Schemas;

use App\Models\ProviderAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class KnowledgeBaseEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Entri knowledge base')
                    ->description('Sumber jawaban AI. Saat pelanggan bertanya bebas (bukan menu/kata kunci), agen AI mencari entri paling relevan lalu menjawab. Tanpa API key LLM, jawaban memakai isi entri; dengan API key, jawaban dirangkai natural berdasarkan entri ini.')
                    ->schema([
                        Select::make('provider_account_id')
                            ->label('Akun provider (nomor)')
                            ->options(fn (): array => ProviderAccount::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->native(false),
                        TextInput::make('title')
                            ->label('Judul / topik')
                            ->placeholder('Contoh: Jam operasional, Ongkir, Cara pesan')
                            ->required()
                            ->maxLength(160),
                        TagsInput::make('keywords')
                            ->label('Kata kunci tambahan (opsional)')
                            ->helperText('Sinonim/istilah lain agar lebih mudah ditemukan. Mis. "buka", "tutup", "operasional".')
                            ->columnSpanFull(),
                        Textarea::make('content')
                            ->label('Isi jawaban')
                            ->rows(5)
                            ->required()
                            ->maxLength(4096)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->required(),
                    ])
                    ->columns(['md' => 2]),
            ]);
    }
}
