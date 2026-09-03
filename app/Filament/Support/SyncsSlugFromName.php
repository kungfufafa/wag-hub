<?php

namespace App\Filament\Support;

use App\Support\UniqueIdentifier;
use Closure;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

final class SyncsSlugFromName
{
    public static function afterStateUpdated(): Closure
    {
        return function (string $operation, mixed $state, Set $set, Get $get, mixed $old): void {
            if ($operation !== 'create') {
                return;
            }

            $currentSlug = (string) ($get('slug') ?? '');
            $previousAuto = UniqueIdentifier::slugFromName((string) $old);

            if ($currentSlug === '' || $currentSlug === $previousAuto) {
                $set('slug', UniqueIdentifier::slugFromName((string) $state));
            }
        };
    }
}
