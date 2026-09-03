<?php

namespace App\Models\Concerns;

use App\Support\UniqueIdentifier;

trait GeneratesSlugFromName
{
    public static function bootGeneratesSlugFromName(): void
    {
        static::creating(function (self $model): void {
            if (filled($model->slug) || blank($model->name)) {
                return;
            }

            $model->slug = UniqueIdentifier::uniqueSlug(
                (string) $model->name,
                fn (string $slug): bool => $model->newQuery()->where('slug', $slug)->exists(),
            );
        });
    }
}
