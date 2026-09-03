<?php

namespace App\Filament\Resources\Concerns;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

trait HasTableCardToggle
{
    public string $viewMode = 'table';

    public function setViewMode(string $mode): void
    {
        $this->viewMode = $mode === 'cards' ? 'cards' : 'table';
    }

    /**
     * @return array<Action>
     */
    protected function viewModeActions(): array
    {
        return [
            Action::make('tableView')
                ->label('Tabel')
                ->icon(Heroicon::OutlinedTableCells)
                ->color($this->viewMode === 'table' ? 'primary' : 'gray')
                ->outlined($this->viewMode !== 'table')
                ->action(fn (): mixed => $this->setViewMode('table')),
            Action::make('cardView')
                ->label('Kartu')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->color($this->viewMode === 'cards' ? 'primary' : 'gray')
                ->outlined($this->viewMode !== 'cards')
                ->action(fn (): mixed => $this->setViewMode('cards')),
        ];
    }
}
