<?php

namespace App\Modules\Orders\Support;

/**
 * Kiểu giao the buyer picked on the marketplace (Shopee "Hỏa Tốc", "Nhanh", "Tiết kiệm", "Hàng Cồng Kềnh"; TikTok's
 * delivery option), grouped so the order list can filter and sort by it. The marketplace's own words are kept in
 * orders.delivery_option; this class only decides the group.
 */
final class DeliveryType
{
    public const EXPRESS = 'express';

    public const STANDARD = 'standard';

    public const ECONOMY = 'economy';

    public const BULKY = 'bulky';

    public const OTHER = 'other';

    /** Display order and labels (same words as Shopee's Seller Center). */
    public const LABELS = [
        self::EXPRESS => 'Hỏa tốc',
        self::STANDARD => 'Nhanh',
        self::ECONOMY => 'Tiết kiệm',
        self::BULKY => 'Cồng kềnh',
        self::OTHER => 'Khác',
    ];

    /**
     * @param  string|null  $option  the type the buyer chose (Shopee checkout_shipping_carrier, TikTok delivery_option_name)
     * @param  string|null  $carrier  the carrier doing the job (SPX Instant, SPX Express, GHN…): a hint when the type is missing
     */
    public static function classify(?string $option, ?string $carrier = null): ?string
    {
        $o = self::plain($option);
        if ($o !== '') {
            return match (true) {
                self::has($o, ['hoa toc', 'instant', 'same day', 'sameday', 'trong ngay', 'gio', 'hour', 'now', 'ahamove', 'grab']) => self::EXPRESS,
                self::has($o, ['cong kenh', 'bulky', 'hang nang', 'heavy', 'oversize']) => self::BULKY,
                self::has($o, ['tiet kiem', 'economy', 'saver', 'eco']) => self::ECONOMY,
                self::has($o, ['nhanh', 'standard', 'tieu chuan', 'thuong', 'express', 'regular']) => self::STANDARD,
                default => self::OTHER,
            };
        }
        // No type from the marketplace (orders read before it was kept): the carrier's name tells the obvious cases.
        // "Giao Hàng Tiết Kiệm" / "Giao Hàng Nhanh" are carrier names, not types, so they decide nothing.
        $c = self::plain($carrier);

        return match (true) {
            $c === '' => null,
            self::has($c, ['instant', 'hoa toc', 'ahamove', 'grab', 'same day']) => self::EXPRESS,
            self::has($c, ['bulky', 'cong kenh']) => self::BULKY,
            self::has($c, ['spx express', 'shopee express', 'standard']) => self::STANDARD,
            default => null,
        };
    }

    private static function plain(?string $text): string
    {
        $t = mb_strtolower(trim((string) $text));
        $t = strtr($t, ['đ' => 'd']);
        $t = (string) preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($t, \Normalizer::FORM_D) ?: $t);

        return (string) preg_replace('/\s+/', ' ', $t);
    }

    /** @param  list<string>  $words */
    private static function has(string $text, array $words): bool
    {
        foreach ($words as $w) {
            if (preg_match('/(^|[^a-z0-9])'.preg_quote($w, '/').'($|[^a-z0-9])/', $text)) {
                return true;
            }
        }

        return false;
    }
}
