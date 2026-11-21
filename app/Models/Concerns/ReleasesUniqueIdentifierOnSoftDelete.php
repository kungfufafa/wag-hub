<?php

namespace App\Models\Concerns;

use App\Support\UniqueIdentifier;

trait ReleasesUniqueIdentifierOnSoftDelete
{
    public static function bootReleasesUniqueIdentifierOnSoftDelete(): void
    {
        static::deleting(function (self $model): void {
            if ($model->isForceDeleting()) {
                return;
            }

            $column = $model->uniqueIdentifierColumn();

            $attributes = [
                $column => UniqueIdentifier::release((string) $model->{$column}, $model->getKey()),
            ];

            if (($activeColumn = $model->deactivatedOnSoftDeleteColumn()) !== null) {
                $attributes[$activeColumn] = false;
            }

            $model->forceFill($attributes)->saveQuietly();
        });
    }

    abstract protected function uniqueIdentifierColumn(): string;

    protected function deactivatedOnSoftDeleteColumn(): ?string
    {
        return 'is_active';
    }
}
