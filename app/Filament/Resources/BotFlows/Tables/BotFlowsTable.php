<?php

namespace App\Filament\Resources\BotFlows\Tables;

use App\Models\BotFlow;
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

class BotFlowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('priority')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('providerAccount.name')
                    ->label('Akun / nomor')
                    ->sortable(),
                TextColumn::make('trigger_type')
                    ->label('Pemicu')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'welcome' ? 'Sapaan' : 'Kata kunci')
                    ->color(fn (string $state): string => $state === 'welcome' ? 'info' : 'success'),
                TextColumn::make('options')
                    ->label('Pilihan')
                    ->formatStateUsing(fn (mixed $state, BotFlow $record): string => count($record->optionList()).' opsi'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('priority')
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
            ->emptyStateHeading('Belum ada menu bot')
            ->emptyStateDescription('Buat menu bernomor untuk memandu pelanggan: jam operasional, katalog, atau serahkan ke agen.');
    }
}
