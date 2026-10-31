<?php

namespace App\Modules\Orders\Services;

use App\Modules\Marketplace\Connectors\Data\OrderData;
use App\Modules\Marketplace\Connectors\Data\OrderItemData;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Events\OrderImported;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Steps [4]–[6] of ORDER-LIFECYCLE §3: upsert the order from the marketplace snapshot under a row lock, record
 * the status change, apply the stock policy. Safe against duplicates (same snapshot twice), out-of-order events
 * (older snapshot after a newer one) and concurrent workers on the same order.
 */
class OrderIngestor
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const STALE = 'stale';

    public const IGNORED_TRANSITION = 'ignored_transition';

    public function __construct(
        private readonly StatusMapper $statuses,
        private readonly OrderStockSync $stock,
    ) {}

    /**
     * Must run inside the tenant context of $account.
     *
     * @return array{result: string, order: Order}
     */
    public function ingest(MarketplaceAccount $account, OrderData $data, string $source, ?int $webhookEventId = null, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($account, $data, $source, $webhookEventId, $actorId) {
            $order = $this->lockOrCreate($account, $data);
            $isNew = $order->wasRecentlyCreated;
            $status = $this->statuses->map($account->marketplace, $data->rawStatus);

            if (! $isNew) {
                if ($order->external_updated_at && $data->updatedAt->lessThan($order->external_updated_at)) {
                    return ['result' => self::STALE, 'order' => $order];
                }

                if ($order->status === $status && $order->raw_status === $data->rawStatus
                    && $order->external_updated_at?->equalTo($data->updatedAt)) {
                    return ['result' => self::UNCHANGED, 'order' => $order];
                }

                if (! OrderStatus::canMove($order->status, $status)) {
                    $order->forceFill(['sync_warning' => "Bỏ qua chuyển trạng thái {$order->status} → {$status} (không hợp lệ theo quy trình)."])->save();

                    return ['result' => self::IGNORED_TRANSITION, 'order' => $order];
                }
            }

            $from = $isNew ? null : $order->status;
            $this->fill($order, $data, $status);
            $this->upsertItems($account, $order, $data->items);

            if ($from !== $status) {
                OrderStatusHistory::query()->create([
                    'order_id' => $order->id,
                    'from_status' => $from,
                    'to_status' => $status,
                    'raw_status' => $data->rawStatus,
                    'source' => $source,
                    'webhook_event_id' => $webhookEventId,
                    'actor_id' => $actorId,
                    'occurred_at' => $data->updatedAt,
                ]);
            }

            $this->stock->apply($order);

            if ($isNew) {
                $tenantId = $order->tenant_id;
                $orderId = $order->id;
                DB::afterCommit(fn () => OrderImported::dispatch($tenantId, $orderId));
            }

            return ['result' => $isNew ? self::CREATED : self::UPDATED, 'order' => $order];
        }, 5);
    }

    /**
     * Locks the order row, creating it if needed. A locking read of a row that does not exist takes a gap lock,
     * and two workers holding the same gap then deadlock on insert; so absence is checked with a plain read, the
     * insert is protected by the unique key, and only existing rows are locked (by primary key).
     */
    private function lockOrCreate(MarketplaceAccount $account, OrderData $data): Order
    {
        $existingId = fn () => Order::query()->where('marketplace_account_id', $account->id)
            ->where('external_order_id', $data->externalOrderId)->value('id');
        $lock = fn (int $id) => Order::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if ($id = $existingId()) {
            return $lock($id);
        }

        try {
            $order = new Order;
            $order->forceFill([
                'marketplace_account_id' => $account->id,
                'marketplace' => $account->marketplace,
                'external_order_id' => $data->externalOrderId,
                'raw_status' => $data->rawStatus,
                'status' => OrderStatus::UNKNOWN_STATUS,
                'warehouse_id' => $account->warehouse_id,
            ])->save();

            return $order;
        } catch (UniqueConstraintViolationException) {
            // Another worker created it first and has committed. A locking read is a "current" read (a plain read
            // would still use this transaction's REPEATABLE READ snapshot and not see the row); the row exists, so
            // this locks the record only.
            return Order::query()->where('marketplace_account_id', $account->id)->where('external_order_id', $data->externalOrderId)
                ->lockForUpdate()->first() ?? throw new \RuntimeException('Order vanished after duplicate insert.');
        }
    }

    private function fill(Order $order, OrderData $data, string $status): void
    {
        $becameShipped = OrderStatus::isShipped($status) && ! OrderStatus::isShipped($order->status ?? '');

        $order->forceFill([
            'raw_status' => $data->rawStatus,
            'status' => $status,
            'payment_method' => $data->paymentMethod,
            'is_cod' => $data->isCod,
            'currency' => $data->currency,
            'subtotal' => $data->subtotal,
            'shipping_fee' => $data->shippingFee,
            'discount' => $data->discount,
            'total' => $data->total,
            'buyer_name' => $data->buyerName,
            'buyer_phone_masked' => self::maskPhone($data->buyerPhone),
            'shipping_address' => $data->shippingAddress,
            'shipping_carrier' => $data->carrier,
            'tracking_number' => $data->trackingNumber,
            'buyer_note' => $data->buyerNote,
            'placed_at' => $data->placedAt ?? $order->placed_at ?? now(),
            'paid_at' => $data->paidAt,
            'ship_by_at' => $data->shipByAt,
            'external_updated_at' => $data->updatedAt,
            'handed_over_at' => $becameShipped ? ($order->handed_over_at ?? now()) : $order->handed_over_at,
            'delivered_at' => in_array($status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true) ? ($order->delivered_at ?? now()) : $order->delivered_at,
            'cancelled_at' => $status === OrderStatus::CANCELLED ? ($order->cancelled_at ?? now()) : $order->cancelled_at,
            'cancel_reason' => $data->cancelReason ?? $order->cancel_reason,
            'sync_warning' => $status === OrderStatus::UNKNOWN_STATUS ? "Trạng thái sàn chưa được ánh xạ: {$data->rawStatus}" : null,
            'raw' => $data->raw,
            'version' => ($order->version ?? 0) + 1,
        ])->save();
    }

    /**
     * @param  array<int, OrderItemData>  $items
     */
    private function upsertItems(MarketplaceAccount $account, Order $order, array $items): void
    {
        $existing = $order->items()->get()->keyBy('external_item_id');

        foreach ($items as $data) {
            $listing = $data->externalSkuId ? $this->listingVariant($account, $data) : null;
            $item = $existing->get($data->externalItemId) ?? new OrderItem(['order_id' => $order->id, 'external_item_id' => $data->externalItemId]);

            $item->fill([
                'external_product_id' => $data->externalProductId,
                'external_sku_id' => $data->externalSkuId,
                'listing_variant_id' => $listing?->id,
                'seller_sku' => $data->sellerSku,
                'name' => $data->name,
                'variant_name' => $data->variantName,
                'quantity' => $item->exists ? $item->quantity : $data->quantity, // quantity changes need a partial-cancel flow (not in MVP)
                'unit_price' => $data->unitPrice,
                'discount' => $data->discount,
            ]);

            // The internal SKU is fixed once set, because stock may already be held against it.
            if ($item->product_variant_id === null && $listing?->isMapped()) {
                $item->product_variant_id = $listing->product_variant_id;
            }
            $item->mapping_status = $item->product_variant_id ? ListingVariant::MAPPED : ListingVariant::UNMAPPED;
            $item->save();
        }
    }

    private function listingVariant(MarketplaceAccount $account, OrderItemData $data): ListingVariant
    {
        $find = fn () => ListingVariant::query()->where('marketplace_account_id', $account->id)->where('external_sku_id', $data->externalSkuId)->first();

        if ($found = $find()) {
            return $found;
        }

        try {
            return ListingVariant::query()->create([
                'marketplace_account_id' => $account->id,
                'external_sku_id' => $data->externalSkuId,
                'seller_sku' => $data->sellerSku,
                'name' => trim($data->name.' '.($data->variantName ?? '')),
                'price' => $data->unitPrice,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Current (locking) read: the plain snapshot read would not see the row committed by the other worker.
            return ListingVariant::query()->where('marketplace_account_id', $account->id)->where('external_sku_id', $data->externalSkuId)
                ->lockForUpdate()->first() ?? throw new \RuntimeException('Listing variant vanished after duplicate insert.');
        }
    }

    public static function maskPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) <= 4 ? '****' : str_repeat('*', strlen($digits) - 3).substr($digits, -3);
    }
}
