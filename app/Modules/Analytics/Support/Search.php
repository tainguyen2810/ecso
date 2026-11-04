<?php

namespace App\Modules\Analytics\Support;

/** Helpers shared by the report queries. */
final class Search
{
    /** A LIKE pattern for a "contains" search: % and _ typed by the user are matched literally, not as wildcards. */
    public static function contains(string $q): string
    {
        return '%'.addcslashes($q, '%_\\').'%';
    }
}
