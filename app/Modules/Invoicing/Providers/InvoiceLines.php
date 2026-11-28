<?php

namespace App\Modules\Invoicing\Providers;

/**
 * Amounts per line and per VAT rate from the neutral invoice InvoicePayload builds (unit prices before VAT, discount
 * per line, tax_rate 0/5/8/10, -2 for "không chịu thuế", null on a sales invoice). Providers that want the totals
 * spelled out (Viettel, VNPT, MISA) take them from here, so all of them round the same way: per line, to the đồng.
 */
final class InvoiceLines
{
    /**
     * @param  array<string, mixed>  $invoice
     * @return array{lines: array<int, array<string, mixed>>, rates: array<string, array{rate: int|null, net: int, vat: int}>, net: int, vat: int, discount: int, total: int}
     */
    public static function compute(array $invoice): array
    {
        $lines = [];
        $rates = [];
        foreach ((array) ($invoice['items'] ?? []) as $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $price = (float) ($item['unit_price'] ?? 0);
            $discount = (int) round((float) ($item['discount_amount'] ?? 0));
            $gross = (int) round($qty * $price);
            $net = $gross - $discount;
            $rate = array_key_exists('tax_rate', $item) && $item['tax_rate'] !== null ? (int) $item['tax_rate'] : null;
            $vat = $rate !== null && $rate > 0 ? (int) round($net * $rate / 100) : 0;
            $lines[] = $item + ['gross' => $gross, 'discount' => $discount, 'net' => $net, 'rate' => $rate, 'vat' => $vat, 'total' => $net + $vat];
            $key = $rate === null ? 'none' : (string) $rate;
            $rates[$key] ??= ['rate' => $rate, 'net' => 0, 'vat' => 0];
            $rates[$key]['net'] += $net;
            $rates[$key]['vat'] += $vat;
        }
        $net = array_sum(array_column($lines, 'net'));
        $vat = array_sum(array_column($lines, 'vat'));

        return ['lines' => $lines, 'rates' => $rates, 'net' => $net, 'vat' => $vat, 'discount' => array_sum(array_column($lines, 'discount')), 'total' => $net + $vat];
    }

    /** "10%", "KCT" (không chịu thuế) or null, as VNPT and MISA name a rate. */
    public static function rateName(?int $rate): ?string
    {
        return match (true) {
            $rate === null => null,
            $rate === -2 => 'KCT',
            $rate === -1 => 'KKKNT',
            default => $rate.'%',
        };
    }
}
