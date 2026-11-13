<?php

namespace App\Shared\Support;

use Illuminate\Support\Str;

/**
 * Recognises the column headers of files exported from other sales apps (Sapo, KiotViet), so a shop moving to
 * EzSell can upload those files as they are. Headers are compared without accents, case or punctuation:
 * "PL_Giá bán lẻ", "pl gia ban le" and "PL-GIÁ BÁN LẺ*" are the same column.
 */
class ImportHeaders
{
    public const EZSELL = 'ezsell';

    public const SAPO = 'sapo';

    public const KIOTVIET = 'kiotviet';

    public const LABELS = [self::EZSELL => 'EzSell', self::SAPO => 'Sapo', self::KIOTVIET => 'KiotViet'];

    /** Headers only one app writes: the first one found names the source of the file. */
    private const SIGNS = [
        self::SAPO => ['/^pl_gia_/', '/^lc_.+_ton_kho/', '/^gia_tri_thuoc_tinh_\d$/', '/^loai_san_pham$/', '/^no_hien_tai$/', '/^ma_sku$/'],
        self::KIOTVIET => ['/^ma_hang$/', '/^ten_hang$/', '/^nhom_hang/', '/^dang_kinh_doanh$/', '/^no_can_thu_hien_tai$/', '/^ma_hh_lien_quan$/', '/^dvt$/', '/^khu_vuc_giao_hang$/', '/^diem_hien_tai$/'],
    ];

    public static function normalize(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii(str_replace(['đ', 'Đ'], 'd', $header)))), '_');
    }

    /** @param  list<string>  $headers */
    public static function source(array $headers, string $default = self::EZSELL): string
    {
        $normalized = array_map(self::normalize(...), $headers);
        foreach (self::SIGNS as $source => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_grep($pattern, $normalized)) {
                    return $source;
                }
            }
        }

        return $default;
    }

    /**
     * Picks, for each key, the first column whose header matches one of its patterns (patterns earlier in the list win).
     *
     * @param  list<string>  $headers
     * @param  array<string, list<string>>  $aliases  key => regex patterns over normalized headers
     * @return array<string, int> key => column index
     */
    public static function map(array $headers, array $aliases): array
    {
        $normalized = array_map(self::normalize(...), $headers);
        $map = [];
        $taken = [];
        foreach ($aliases as $key => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($normalized as $index => $header) {
                    if (! isset($taken[$index]) && $header !== '' && preg_match($pattern, $header)) {
                        $map[$key] = $index;
                        $taken[$index] = true;
                        continue 3;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * A number as the other apps write it: "150,000", "150.000", "1.250.000,5", "12.5" or "1e3" → a plain decimal string.
     * Anything else is returned as it is, for the importer to reject.
     */
    public static function number(string $value): string
    {
        $v = str_replace([' ', "\u{00A0}", '₫', 'đ', 'VND', 'vnd'], '', trim($value));
        if ($v === '' || is_numeric($v) && ! preg_match('/^-?\d{1,3}\.\d{3}$/', $v)) {
            return $v;
        }
        if (preg_match('/^-?\d{1,3}([.,])\d{3}(\1\d{3})*$/', $v, $m)) {
            return str_replace($m[1], '', $v); // thousands only
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})*,\d+$/', $v)) {
            return str_replace(['.', ','], ['', '.'], $v); // 1.250.000,5
        }
        if (preg_match('/^-?\d{1,3}(,\d{3})*\.\d+$/', $v)) {
            return str_replace(',', '', $v); // 1,250,000.5
        }

        return $value;
    }

    /** "12" / "12.0" / "12,00" → "12"; a fraction or text stays as it is. */
    public static function integer(string $value): string
    {
        $n = self::number($value);
        if (is_numeric($n) && (float) $n === floor((float) $n)) {
            return (string) (int) $n;
        }

        return $value;
    }
}
