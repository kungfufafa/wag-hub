<?php

namespace App\Filament\Support;

use Filament\Tables\Table;

final class ConfigurationListLayout
{
    public static function isCards(Table $table): bool
    {
        $livewire = $table->getLivewire();

        return is_object($livewire)
            && property_exists($livewire, 'viewMode')
            && $livewire->viewMode === 'cards';
    }

    /**
     * @return array<string, int>|null
     */
    public static function contentGrid(Table $table): ?array
    {
        return self::isCards($table)
            ? [
                'md' => 2,
                'xl' => 3,
            ]
            : null;
    }
}
