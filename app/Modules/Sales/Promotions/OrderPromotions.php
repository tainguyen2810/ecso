<?php

namespace App\Modules\Sales\Promotions;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\ReservationLedger;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PromotionUsage;
use Illuminate\Validation\ValidationException;

/**
 * Puts promotions on a manual order each time its lines or buyer change (inside ManualOrderService's transaction):
 * money off is added to the order discount the seller typed (orders.discount = typed + promotion, so every report
 * counts it), gift lines are added at 0 ₫, and each promotion received is recorded for its usage limits.
 */
class OrderPromotions
{
    public function __construct(private readonly PromotionEngine $engine, private readonly ReservationLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data  the request; coupon_code / promotions_off / discount when sent
     * @param  string  $typedDiscount  the order discount the seller typed (without any promotion)
     */
    public function apply(Order $order, array $data, string $typedDiscount): PromotionResult
    {
        $code = array_key_exists('coupon_code', $data) ? (filled($data['coupon_code']) ? strtoupper(trim((string) $data['coupon_code'])) : null) : $order->coupon_code;
        $off = array_key_exists('promotions_off', $data) ? (bool) $data['promotions_off'] : (bool) $order->promotions_off;

        // Earlier gift lines go (with any stock they held); the ones still due are added again below.
        $old = $order->items()->whereNotNull('promotion_id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($old !== []) {
            $this->ledger->releaseAll(Order::STOCK_REFERENCE, $old, "Tính lại khuyến mãi đơn {$order->external_order_id}");
            $order->items()->whereKey($old)->delete();
        }
        $items = $order->items()->with('variant.product')->get();
        $lines = $items->map(fn (OrderItem $i) => [
            'variant_id' => $i->product_variant_id !== null ? (int) $i->product_variant_id : null,
            'category_id' => $i->variant?->product?->category_id !== null ? (int) $i->variant->product->category_id : null,
            'quantity' => (int) $i->quantity,
            'unit_price' => (string) $i->unit_price,
            'discount' => (string) $i->discount,
        ])->all();
        $groupId = $order->customer_id ? Customer::query()->whereKey($order->customer_id)->value('customer_group_id') : null;

        $result = $this->engine->evaluate($lines, [
            'channel_id' => $order->sales_channel_id !== null ? (int) $order->sales_channel_id : null,
            'customer_id' => $order->customer_id !== null ? (int) $order->customer_id : null,
            'group_id' => $groupId !== null ? (int) $groupId : null,
            'order_id' => $order->id,
            'code' => $code,
            'auto' => ! $off,
            // An order being edited keeps the promotions running when it was placed.
            'at' => $order->placed_at ?? now(),
        ]);
        if ($code !== null && $result->codeError !== null) {
            throw ValidationException::withMessages(['coupon_code' => $result->codeError]);
        }

        $n = 0;
        foreach ($result->gifts() as $gift) {
            $variant = ProductVariant::query()->with('product')->find($gift['variant_id']);
            if ($variant === null || ! $variant->isSellable()) {
                continue; // a gift that stopped selling is simply not given
            }
            $item = new OrderItem;
            $item->forceFill([
                'order_id' => $order->id,
                'external_item_id' => 'g'.(++$n).'-'.$order->version,
                'product_variant_id' => $variant->id,
                'seller_sku' => $variant->sku,
                'name' => $variant->product->name ?? $variant->sku,
                'variant_name' => $variant->name,
                'quantity' => $gift['quantity'],
                'unit_price' => '0.00',
                'discount' => '0.00',
                'promotion_id' => $gift['promotion_id'],
                'mapping_status' => 'mapped',
            ])->save();
        }

        $order->forceFill([
            'promotion_discount' => $result->discount,
            'discount' => bcadd($typedDiscount, $result->discount, 2),
            'coupon_code' => $result->code(),
            'promotions_off' => $off,
        ])->save();

        PromotionUsage::query()->where('order_id', $order->id)->delete();
        foreach ($result->applied as $a) {
            PromotionUsage::query()->create([
                'promotion_id' => $a['promotion']->id,
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'amount' => $a['amount'],
            ]);
        }

        return $result;
    }

    /** The order discount the seller typed, without the promotions already on it. */
    public static function typedDiscount(Order $order): string
    {
        $typed = bcsub((string) $order->discount, (string) $order->promotion_discount, 2);

        return bccomp($typed, '0', 2) < 0 ? '0.00' : $typed;
    }
}
