<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $open = in_array($this->status, OrderStatus::OPEN, true);

        return [
            'id' => $this->ulid,
            'marketplace' => $this->marketplace,
            'source' => $this->isManual() ? 'manual' : 'marketplace',
            'shop' => $this->whenLoaded('account', fn () => $this->account ? ['id' => $this->account->ulid, 'name' => $this->account->shop_name, 'marketplace' => $this->account->marketplace] : null),
            'channel' => $this->whenLoaded('channel', fn () => $this->channel ? ['id' => $this->channel->ulid, 'name' => $this->channel->name, 'type' => $this->channel->type] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->ulid, 'code' => $this->customer->code, 'name' => $this->customer->name] : null),
            'paid_amount' => $this->paid_amount,
            'refunded_amount' => $this->refunded_amount,
            'payment_status' => $this->payment_status,
            // none | partial | full: money paid back to the buyer, next to payment_status (which counts payments only).
            'refund_status' => match (true) {
                bccomp((string) $this->refunded_amount, '0', 2) <= 0 => 'none',
                bccomp((string) $this->refunded_amount, (string) $this->paid_amount, 2) >= 0 => 'full',
                default => 'partial',
            },
            'balance_due' => $this->isManual() && $this->status !== OrderStatus::CANCELLED ? $this->balanceDue() : null,
            'external_order_id' => $this->external_order_id,
            'status' => $this->status,
            'version' => (int) $this->version,
            'raw_status' => $this->raw_status,
            'payment_method' => $this->payment_method,
            'is_cod' => $this->is_cod,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'shipping_fee' => $this->shipping_fee,
            'discount' => $this->discount,
            'tax_rate' => $this->tax_rate,
            'prices_include_tax' => (bool) ($this->prices_include_tax ?? true),
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'buyer_name' => $this->buyer_name,
            // Manual and own-website orders: the seller's own customer, full phone; marketplace orders: masked only.
            'buyer_phone' => $this->ownsCustomerData() ? ($this->buyer_phone ?? $this->buyer_phone_masked) : $this->buyer_phone_masked,
            'buyer_email' => $this->when($this->ownsCustomerData(), fn () => $this->buyer_email),
            'addresses' => $this->when($this->ownsCustomerData(), fn () => $this->addresses),
            'external_number' => $this->external_number,
            'tax_total' => $this->tax_total,
            'fee_total' => $this->fee_total,
            'coupon_codes' => $this->coupon_codes ?? [],
            'transaction_id' => $this->transaction_id,
            'shipping_address' => $this->shipping_address,
            'shipping_street' => $this->shipping_street,
            'shipping_province_code' => $this->shipping_province_code,
            'shipping_ward_code' => $this->shipping_ward_code,
            'carrier' => $this->shipping_carrier,
            'tracking_number' => $this->tracking_number,
            'buyer_note' => $this->buyer_note,
            'tags' => $this->tags ?? [],
            'placed_at' => $this->placed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'ship_by_at' => $this->ship_by_at?->toIso8601String(),
            'deliver_on' => $this->deliver_on?->toDateString(),
            'handed_over_at' => $this->handed_over_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'deleted_on_channel_at' => $this->deleted_on_channel_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'external_updated_at' => $this->external_updated_at?->toIso8601String(),
            'flags' => [
                'shortage' => $this->has_shortage,
                'unmapped' => $this->has_unmapped,
                'overdue' => $open && $this->ship_by_at?->isPast(),
                'due_soon' => $open && $this->ship_by_at !== null && ! $this->ship_by_at->isPast()
                    && $this->ship_by_at->lt(now()->addHours(config('ecso.orders.due_soon_hours'))),
                'printed' => $this->packing_slip_printed_at !== null,
            ],
            'sync_warning' => $this->sync_warning,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? ['id' => $this->warehouse->ulid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->ulid, 'name' => $this->assignee->name] : null),
            'items_count' => $this->whenCounted('items'),
            'items_preview' => $this->whenLoaded('items', fn () => $this->items->take(3)->map(fn ($i) => [
                'name' => $i->name, 'variant_name' => $i->variant_name, 'quantity' => $i->quantity, 'sku' => $i->variant->sku ?? $i->seller_sku,
            ])),
        ];
    }
}
