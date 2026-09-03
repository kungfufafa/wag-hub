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

            $model->forceFill([
                $column => UniqueIdentifier::release((string) $model->{$column}, $model->getKey()),
                'is_active' => false,
            ])->saveQuietly();
        });
    }

    abstract protected function uniqueIdentifierColumn(): string;
}
