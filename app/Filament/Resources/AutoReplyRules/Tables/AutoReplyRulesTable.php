<?php

namespace App\Filament\Resources\AutoReplyRules\Tables;

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

class AutoReplyRulesTable
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
                TextColumn::make('match_type')
                    ->label('Pemicu')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'welcome' => 'Sapaan',
                        'keyword' => 'Kata kunci',
                        'fallback' => 'Cadangan',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'welcome' => 'info',
                        'keyword' => 'success',
                        'fallback' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('keywords')
                    ->label('Kata kunci')
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) ? implode(', ', $state) : '')
                    ->limit(40)
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('priority')
            ->filters([
                SelectFilter::make('provider_account_id')
                    ->label('Akun provider')
                    ->options(fn (): array => ProviderAccount::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('match_type')
                    ->label('Pemicu')
                    ->options([
                        'welcome' => 'Sapaan',
                        'keyword' => 'Kata kunci',
                        'fallback' => 'Cadangan',
                    ]),
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
            ->emptyStateHeading('Belum ada balasan otomatis')
            ->emptyStateDescription('Tambah aturan untuk membalas pesan masuk secara otomatis: sapaan, kata kunci, atau cadangan.');
    }
}
