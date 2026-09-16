<?php

namespace App\Filament\Resources\KnowledgeBaseEntries\Tables;

use App\Models\ProviderAccount;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class KnowledgeBaseEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('providerAccount.name')
                    ->label('Akun / nomor')
                    ->sortable(),
                TextColumn::make('content')
                    ->label('Isi')
                    ->limit(60),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('title')
            ->filters([
                SelectFilter::make('provider_account_id')
                    ->label('Akun provider')
                    ->options(fn (): array => ProviderAccount::query()->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada knowledge base')
            ->emptyStateDescription('Tambahkan info bisnis (jam buka, ongkir, cara pesan) agar agen AI bisa menjawab pertanyaan bebas pelanggan.');
    }
}
