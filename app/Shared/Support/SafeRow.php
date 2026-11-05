<?php

namespace App\Shared\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;

/**
 * A spreadsheet row that never turns text into a formula.
 *
 * OpenSpout's Row::fromValues() writes any text starting with "=" as a formula, so a customer named "=HYPERLINK(...)"
 * would run in Excel when the export is opened. Here text is always a text cell; in a CSV file (where the reader
 * decides) text starting with = + - @ or a tab is prefixed with an apostrophe, unless it is a plain number.
 */
final class SafeRow
{
    /** @param  array<int, mixed>  $values */
    public static function fromValues(array $values, bool $csv = false): Row
    {
        return new Row(array_map(fn ($v) => is_string($v) ? new StringCell(self::text($v, $csv), null) : Cell::fromValue($v), array_values($values)));
    }

    public static function text(string $value, bool $csv = true): string
    {
        if (! $csv || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
