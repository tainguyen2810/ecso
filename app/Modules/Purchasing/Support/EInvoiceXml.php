<?php

namespace App\Modules\Purchasing\Support;

use SimpleXMLElement;

/**
 * Reads a Vietnamese e-invoice XML (the tax authority's format, Thông tư 78 / Quyết định 1450: HDon › DLHDon ›
 * TTChung + NDHDon). Returns the fields "Hoá đơn mua vào" keeps, with goods lines in the same shape the AI invoice
 * reading gives, so the same matching turns both into a purchase order draft. Null when the file is not an invoice.
 */
class EInvoiceXml
{
    private const SHIPPING = '/(ph[ií]|c[uư][oớ]c)\s*(v[aậ]n\s*chuy[eể]n|giao\s*h[aà]ng|ship)|^\s*v[aậ]n\s*chuy[eể]n|^\s*ship/iu';

    /** @return array<string, mixed>|null */
    public static function parse(string $xml): ?array
    {
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }
        // Namespaces differ between providers: drop them so plain paths work.
        $xml = (string) preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml);
        $xml = (string) preg_replace('/<(\/?)\w+:(\w+)/', '<$1$2', $xml);
        $prev = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($root === false) {
            return null;
        }
        $doc = $root->getName() === 'HDon' ? $root : (($root->xpath('//HDon') ?: [null])[0]);
        if (! $doc instanceof SimpleXMLElement) {
            return null;
        }
        $data = $doc->DLHDon;
        $head = $data->TTChung;
        $body = $data->NDHDon;
        $number = self::text($head->SHDon);
        $symbol = self::text($head->KHHDon);
        if ($number === '' || ! isset($body->NBan)) {
            return null;
        }

        $lines = [];
        $discount = 0.0;
        $shipping = 0.0;
        $rates = [];
        foreach ($body->DSHHDVu->HHDVu ?? [] as $item) {
            $kind = (int) (self::text($item->TChat) ?: 1);
            $name = self::text($item->THHDVu);
            $qty = self::num($item->SLuong);
            $price = self::num($item->DGia);
            $amount = self::num($item->ThTien);
            $rate = self::rate(self::text($item->TSuat));
            if ($rate !== null) {
                $rates[(string) $rate] = true;
            }
            if ($kind === 3) { // chiết khấu thương mại
                $discount += abs($amount);

                continue;
            }
            if ($kind === 4 || ($name === '' && $amount == 0.0)) { // ghi chú, diễn giải
                continue;
            }
            if ($kind === 1 && preg_match(self::SHIPPING, $name) === 1 && self::text($item->MHHDVu) === '') {
                $shipping += $amount;

                continue;
            }
            if ($qty > 0 && $amount > 0 && abs($qty * $price - $amount) > 1) {
                $price = round($amount / $qty, 2);
            }
            $lines[] = [
                'name' => $name,
                'code' => self::text($item->MHHDVu),
                'unit' => self::text($item->DVTinh),
                'quantity' => $qty,
                'unit_price' => $kind === 2 ? 0.0 : $price, // hàng khuyến mại
                'amount' => $kind === 2 ? 0.0 : $amount,
                'vat_rate' => $rate,
                'promo' => $kind === 2,
            ];
        }
        $pay = $body->TToan;
        $vat = self::num($pay->TgTThue);
        $total = self::num($pay->TgTTTBSo);
        $discount = max($discount, self::num($pay->TTCKTMai));
        $subtotal = self::num($pay->TgTCThue) ?: array_sum(array_column($lines, 'amount')) + $shipping - $discount;
        $date = substr(self::text($head->NLap), 0, 10);
        $title = mb_strtoupper(self::text($head->THDon));

        return [
            'kind' => str_contains($title, 'BÁN HÀNG') || self::text($head->KHMSHDon) === '2' ? 'sale' : 'vat',
            'series' => self::text($head->KHMSHDon).$symbol,
            'number' => ltrim($number, '0') === '' ? '0' : $number,
            'issued_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null,
            'authority_code' => self::text($doc->MCCQT) ?: null,
            // "C…" symbols are invoices that must carry the tax authority's code; "K…" are without it.
            'needs_authority_code' => str_starts_with(strtoupper($symbol), 'C'),
            'seller' => [
                'name' => self::text($body->NBan->Ten),
                'tax_code' => self::text($body->NBan->MST),
                'address' => self::text($body->NBan->DChi),
                'phone' => self::text($body->NBan->SDThoai),
            ],
            'buyer' => [
                'name' => self::text($body->NMua->Ten) ?: self::text($body->NMua->HVTNMHang),
                'tax_code' => self::text($body->NMua->MST),
            ],
            'currency' => strtoupper(self::text($head->DVTTe) ?: 'VND'),
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping' => $shipping,
            'vat_amount' => $vat,
            'vat_rate' => count($rates) === 1 ? (float) array_key_first($rates) : null,
            'total' => $total ?: $subtotal + $vat,
        ];
    }

    private static function text(?SimpleXMLElement $node): string
    {
        return $node === null ? '' : trim((string) $node);
    }

    private static function num(?SimpleXMLElement $node): float
    {
        $s = str_replace([' ', ','], ['', '.'], self::text($node));

        return is_numeric($s) ? (float) $s : 0.0;
    }

    /** "8%", "10%", "KCT", "KKKNT", "0%". */
    private static function rate(string $s): ?float
    {
        return preg_match('/^(\d+(?:[.,]\d+)?)\s*%$/', $s, $m) === 1 ? (float) str_replace(',', '.', $m[1]) : null;
    }
}
