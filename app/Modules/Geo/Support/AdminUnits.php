<?php

namespace App\Modules\Geo\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads Vietnam's administrative units (database/data/vn-admin-units.json, built by scripts/build-vn-admin-units.py)
 * into geo_provinces / geo_wards. Run by the migration that creates the tables; running it again replaces the list,
 * so a later correction of the data is a new migration that calls load() again.
 */
final class AdminUnits
{
    public const DATA_FILE = 'data/vn-admin-units.json';

    public static function load(): void
    {
        /** @var array{provinces: list<array<string, mixed>>, wards: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents(database_path(self::DATA_FILE)), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data) {
            DB::table('geo_wards')->delete();
            DB::table('geo_provinces')->delete();

            DB::table('geo_provinces')->insert(array_map(fn (array $p) => [
                'code' => $p['code'],
                'name' => $p['name'],
                'short_name' => $p['short_name'],
                'type' => $p['type'],
                'former' => json_encode($p['former'], JSON_UNESCAPED_UNICODE),
            ], $data['provinces']));

            foreach (array_chunk($data['wards'], 500) as $chunk) {
                DB::table('geo_wards')->insert(array_map(fn (array $w) => [
                    'code' => $w['code'],
                    'province_code' => $w['province_code'],
                    'name' => $w['name'],
                    'former' => json_encode($w['former'], JSON_UNESCAPED_UNICODE),
                    'former_hint' => $w['former_hint'] !== '' ? mb_substr($w['former_hint'], 0, 1000) : null,
                    // Name and each former "ward district" folded, kept apart by "|" so words of two names never read as one.
                    'search' => implode(' | ', array_map(fn ($part) => self::fold($part), [$w['name'], ...array_map(fn ($f) => implode(' ', $f), $w['former'])])),
                ], $chunk));
            }
        });
    }

    /** Lower case without Vietnamese marks ("Phường Bình Thạnh" -> "phuong binh thanh"), for matching typed text. */
    public static function fold(string $text): string
    {
        $text = Str::lower(Str::ascii($text));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $text)));
    }
}
