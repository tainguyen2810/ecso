<?php

namespace App\Modules\Orders\Support;

/** Order tags: trimmed, deduplicated case-insensitively, at most MAX of them; an empty list is stored as null. */
final class OrderTags
{
    public const MAX = 10;

    /**
     * @param  array<int, mixed>|null  $tags
     * @return array<int, string>|null
     */
    public static function normalize(?array $tags): ?array
    {
        $clean = collect($tags ?? [])
            ->map(fn ($t) => trim(preg_replace('/\s+/u', ' ', (string) $t) ?? ''))
            ->filter(fn (string $t) => $t !== '')
            ->unique(fn (string $t) => mb_strtolower($t))
            ->take(self::MAX)
            ->values()
            ->all();

        return $clean === [] ? null : $clean;
    }
}
