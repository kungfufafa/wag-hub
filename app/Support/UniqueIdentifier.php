<?php

namespace App\Support;

use Illuminate\Support\Str;

final class UniqueIdentifier
{
    public static function slugFromName(string $name, int $maxLength = 80): string
    {
        $slug = Str::slug($name);
        $slug = $slug !== '' ? $slug : 'item';

        return self::fit($slug, $maxLength);
    }

    /**
     * @param  callable(string): bool  $exists
     */
    public static function uniqueSlug(string $name, callable $exists, int $maxLength = 80): string
    {
        $base = self::slugFromName($name, $maxLength);
        $slug = $base;
        $i = 2;

        while ($exists($slug)) {
            $suffix = '-'.$i;
            $slug = self::fit($base, $maxLength - strlen($suffix)).$suffix;
            $i++;
        }

        return $slug;
    }

    public static function release(string $value, int|string $id, int $maxLength = 80): string
    {
        $suffix = '--d'.$id;

        return self::fit($value, $maxLength - strlen($suffix)).$suffix;
    }

    private static function fit(string $value, int $maxLength): string
    {
        $maxLength = max(1, $maxLength);

        return strlen($value) <= $maxLength ? $value : substr($value, 0, $maxLength);
    }
}
