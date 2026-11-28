<?php

namespace App\Modules\Invoicing\Support;

/** Amount in Vietnamese words ("Số tiền bằng chữ"), same reading as the cash vouchers: 1.250.000 → "Một triệu hai trăm năm mươi nghìn đồng". */
final class VndWords
{
    private const DIGITS = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

    public static function spell(int|float $value): string
    {
        $n = (int) round(abs($value));
        if ($n === 0) {
            return 'Không đồng';
        }
        $groups = [];
        while ($n > 0) {
            $groups[] = $n % 1000;
            $n = intdiv($n, 1000);
        }
        $units = ['', ' nghìn', ' triệu'];
        $parts = [];
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            $g = $groups[$i];
            if ($g === 0) {
                if ($i > 0 && $i % 3 === 0 && $parts !== []) {
                    $parts[] = 'tỷ';
                }

                continue;
            }
            $scale = $i === 0 ? '' : ($i % 3 === 0 ? ' tỷ' : $units[$i % 3]);
            $parts[] = self::triple($g, $parts !== []).$scale;
        }
        $text = trim((string) preg_replace('/\s+/', ' ', implode(' ', $parts)));

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1).' đồng';
    }

    private static function triple(int $n, bool $full): string
    {
        [$h, $t, $u] = [intdiv($n, 100), intdiv($n % 100, 10), $n % 10];
        $out = [];
        if ($h > 0 || $full) {
            $out[] = self::DIGITS[$h].' trăm';
        }
        if ($t > 1) {
            $out[] = self::DIGITS[$t].' mươi';
            $out[] = match (true) {
                $u === 1 => 'mốt', $u === 5 => 'lăm', $u === 4 => 'tư', $u > 0 => self::DIGITS[$u], default => ''
            };
        } elseif ($t === 1) {
            $out[] = 'mười';
            $out[] = match (true) {
                $u === 5 => 'lăm', $u > 0 => self::DIGITS[$u], default => ''
            };
        } elseif ($u > 0) {
            if ($h > 0 || $full) {
                $out[] = 'lẻ';
            }
            $out[] = self::DIGITS[$u];
        }

        return implode(' ', array_filter($out, fn ($s) => $s !== ''));
    }
}
