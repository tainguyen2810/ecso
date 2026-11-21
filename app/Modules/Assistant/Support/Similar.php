<?php

namespace App\Modules\Assistant\Support;

use Illuminate\Support\Str;

/**
 * Loose text matching for AI readings (invoice lines, supplier names) against the catalogue: accents, case
 * and punctuation do not count, model codes ("AX210", "SN580-512") weigh more than plain words.
 */
final class Similar
{
    /** Words that say nothing about which product it is. */
    private const NOISE = ['cai', 'chiec', 'bo', 'hop', 'thung', 'cuon', 'goi', 'loai', 'hang', 'moi', 'chinh', 'hang', 'the', 'va', 'cho', 'co', 'khong', 'mau'];

    /** Company-type words left out when comparing supplier names. */
    private const COMPANY = ['cong', 'ty', 'tnhh', 'co', 'phan', 'cp', 'mtv', 'thuong', 'mai', 'dich', 'vu', 'tm', 'dv', 'xnk', 'doanh', 'nghiep', 'tu', 'nhan', 'ho', 'kinh'];

    public static function normalize(string $text): string
    {
        $t = mb_strtolower(Str::ascii($text));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $t));
    }

    /** "SN580-512", "sn580 512" and "SN580512" are the same code. */
    public static function code(?string $code): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii((string) $code)));
    }

    /** @return list<string> */
    public static function tokens(string $text, bool $company = false): array
    {
        $skip = $company ? array_merge(self::NOISE, self::COMPANY) : self::NOISE;

        return array_values(array_unique(array_filter(explode(' ', self::normalize($text)), fn ($w) => $w !== '' && ! in_array($w, $skip, true))));
    }

    private static function isCode(string $token): bool
    {
        return strlen($token) >= 3 && preg_match('/\d/', $token) === 1 && preg_match('/[a-z]/', $token) === 1;
    }

    /**
     * 0..1: how well $candidate names the same product as $read. Shared model codes count three times a word;
     * a code of one side found inside the other (split or joined) counts too.
     */
    public static function score(string $read, string $candidate): float
    {
        $a = self::tokens($read);
        $b = self::tokens($candidate);
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $joinedB = implode('', $b);
        $weight = fn (string $t) => self::isCode($t) ? 3.0 : (ctype_digit($t) ? 1.5 : 1.0);
        $total = 0.0;
        $hit = 0.0;
        foreach ($a as $t) {
            $w = $weight($t);
            $total += $w;
            if (in_array($t, $b, true) || (self::isCode($t) && str_contains($joinedB, $t))) {
                $hit += $w;
            }
        }
        $totalB = array_sum(array_map($weight, $b));
        $hitB = 0.0;
        $joinedA = implode('', $a);
        foreach ($b as $t) {
            if (in_array($t, $a, true) || (self::isCode($t) && str_contains($joinedA, $t))) {
                $hitB += $weight($t);
            }
        }

        // Mostly how much of the invoice text the product covers, a little how much of the product name is used.
        return round(0.7 * ($hit / $total) + 0.3 * $hitB / $totalB, 3);
    }

    public static function company(string $a, string $b): float
    {
        $x = self::tokens($a, true);
        $y = self::tokens($b, true);
        if ($x === [] || $y === []) {
            return 0.0;
        }
        $common = count(array_intersect($x, $y));

        return $common / max(count($x), count($y));
    }

    /** Only the digits of a tax code ("0316 789 123-001" → "0316789123001"). */
    public static function taxCode(?string $code): string
    {
        return (string) preg_replace('/\D/', '', (string) $code);
    }
}
