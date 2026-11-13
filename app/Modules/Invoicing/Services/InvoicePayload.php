<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Shared\Support\ReportRange;

/**
 * Turns an order into the invoice sent to the provider (SePay's shape: template, series, buyer, items).
 *
 * - Template "1" (hóa đơn GTGT) carries VAT per line at the order's rate; when the shop's prices include VAT the
 *   unit prices are taken back to before tax. Template "2" (hóa đơn bán hàng) has no VAT lines.
 * - The order discount is spread over the goods lines in proportion to their amounts (the last line takes the
 *   rounding), the shipping fee is its own line without VAT (ECSO never taxes shipping), refunded units are left out.
 * - total_amount is the order's total less refunds, so the invoice shows exactly what the buyer paid.
 */
final class InvoicePayload
{
    /** VAT rates the tax authority accepts on a line; anything else is sent as "không chịu thuế". */
    private const RATES = [0, 5, 8, 10];

    private const NO_TAX = -2;

    /**
     * @param  array{name: string, legal_name?: string|null, tax_code?: string|null, address?: string|null, email?: string|null, phone?: string|null, code?: string|null}  $buyer
     * @return array<string, mixed>
     */
    public static function build(Order $order, EInvoiceAccount $account, array $buyer, string $referenceCode): array
    {
        $vat = $account->template_code === '1';
        $rate = (float) $order->tax_rate;
        $lineRate = $vat ? (in_array((int) $rate, self::RATES, true) && (float) (int) $rate === $rate && $rate > 0 ? (int) $rate : self::NO_TAX) : null;
        $included = (bool) ($order->prices_include_tax ?? true);
        $factor = $vat && $lineRate !== null && $lineRate > 0 && $included ? 100 / (100 + $lineRate) : 1.0;

        $goods = $order->items
            ->map(function (OrderItem $i) {
                $qty = max(0, $i->quantity - (int) $i->refunded_quantity);
                $lineDiscount = $i->quantity > 0 ? (float) $i->discount * $qty / $i->quantity : 0.0;

                return ['item' => $i, 'qty' => $qty, 'gross' => (float) $i->unit_price * $qty, 'discount' => $lineDiscount];
            })
            ->filter(fn ($l) => $l['qty'] > 0)
            ->values();

        $orderDiscount = (float) $order->discount;
        $base = $goods->sum(fn ($l) => $l['gross'] - $l['discount']);
        $left = $orderDiscount;
        $items = [];
        foreach ($goods as $n => $l) {
            $share = $n === $goods->count() - 1 ? $left : ($base > 0 ? round($orderDiscount * ($l['gross'] - $l['discount']) / $base) : 0.0);
            $left -= $share;
            /** @var OrderItem $item */
            $item = $l['item'];
            $variant = $item->variant;
            $items[] = array_filter([
                'line_number' => $n + 1,
                'line_type' => 1,
                'item_code' => $variant->sku ?? $item->seller_sku,
                'item_name' => mb_substr(trim($item->name.($item->variant_name ? ' - '.$item->variant_name : '')), 0, 500),
                'unit' => $variant?->product?->unit ?: 'Cái',
                'quantity' => $l['qty'],
                'unit_price' => round((float) $item->unit_price * $factor, 2),
                'discount_amount' => round(($l['discount'] + $share) * $factor, 2) ?: null,
                'tax_rate' => $lineRate,
            ], fn ($v) => $v !== null && $v !== '');
        }
        if ((float) $order->shipping_fee > 0) {
            $items[] = array_filter([
                'line_number' => count($items) + 1,
                'line_type' => 1,
                'item_name' => 'Phí vận chuyển',
                'unit' => 'Lần',
                'quantity' => 1,
                'unit_price' => (float) $order->shipping_fee,
                'tax_rate' => $vat ? self::NO_TAX : null,
            ], fn ($v) => $v !== null);
        }

        $company = filled($buyer['tax_code'] ?? null);

        return [
            'reference_code' => $referenceCode,
            'template_code' => $account->template_code,
            'invoice_series' => $account->invoice_series,
            'issued_date' => ReportRange::now()->format('Y-m-d H:i:s'),
            'currency' => 'VND',
            'payment_method' => 'TM/CK',
            'buyer' => array_filter([
                'type' => $company ? 'company' : 'personal',
                'name' => $buyer['name'],
                'legal_name' => $company ? ($buyer['legal_name'] ?? $buyer['name']) : null,
                'tax_code' => $buyer['tax_code'] ?? null,
                'address' => $buyer['address'] ?? null,
                'email' => $buyer['email'] ?? null,
                'phone' => $buyer['phone'] ?? null,
                'buyer_code' => $buyer['code'] ?? null,
            ], fn ($v) => filled($v)),
            'items' => $items,
            'notes' => 'Đơn '.$order->external_order_id,
            'total_amount' => (int) round((float) $order->total - (float) $order->refunded_amount),
        ];
    }
}
