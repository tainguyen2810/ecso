<?php

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\Services\ReservationLedger;
use App\Modules\Marketplace\Connectors\Data\OrderData;
use App\Modules\Marketplace\Connectors\Data\OrderItemData;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Events\OrderImported;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderRefund;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerResolver;
use App\Modules\Sales\Services\CustomerService;
use App\Modules\Sales\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /** Created before the store's "orders since" moment: not synced (the seller chose to start from that moment). */
    public const SKIPPED = 'skipped';

    /** The order is gone from the store: kept in ECSO, flagged, and cancelled if it had not shipped. */
    public const DELETED = 'deleted_on_channel';

    public function __construct(
        private readonly StatusMapper $statuses,
        private readonly OrderStockSync $stock,
        private readonly CustomerService $customers,
        private readonly ReservationLedger $ledger,
        private readonly ReturnService $returns,
    ) {}

    /**
     * Must run inside the tenant context of $account.
     *
     * @return array{result: string, order: Order|null} order is null only when the result is SKIPPED
     */
    public function ingest(MarketplaceAccount $account, OrderData $data, string $source, ?int $webhookEventId = null, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($account, $data, $source, $webhookEventId, $actorId) {
            if ($this->beforeCutoff($account, $data) && ! Order::query()->where('marketplace_account_id', $account->id)
                ->where('external_order_id', $data->externalOrderId)->exists()) {
                return ['result' => self::SKIPPED, 'order' => null];
            }

            $order = $this->lockOrCreate($account, $data);
            $isNew = $order->wasRecentlyCreated;
            $status = $this->statuses->map($account->marketplace, $data->rawStatus, $account);
            if ($status === OrderStatus::RETURNED && ($isNew || ! in_array($order->status, OrderStatus::SHIPPED, true))) {
                // Refunded/returned before the goods ever left (e.g. Woo "refunded" on an unpacked order): it is a
                // cancel, which gives the held stock back, not a return of goods that never shipped.
                $status = OrderStatus::CANCELLED;
            }

            // An order deleted (trashed) on the store and then restored: it may open again, if it had not shipped.
            $restoring = ! $isNew && $order->deleted_on_channel_at !== null && $order->status === OrderStatus::CANCELLED
                && OrderStatus::policy($status) === OrderStatus::RESERVE && ! $order->returns()->exists();

            if (! $isNew) {
                if ($order->external_updated_at && $data->updatedAt->lessThan($order->external_updated_at)) {
                    return ['result' => self::STALE, 'order' => $order];
                }

                if ($order->status === $status && $order->raw_status === $data->rawStatus
                    && $order->external_updated_at?->equalTo($data->updatedAt)) {
                    return ['result' => self::UNCHANGED, 'order' => $order];
                }

                // One-way store: ECSO moved the order on by itself (packed, handed over, cancelled) and the store still
                // shows an earlier status. ECSO's status stays; the rest of the snapshot is still taken in.
                if (! $account->syncs('push_status') && ! OrderStatus::canMove($order->status, $status) && OrderStatus::canMove($status, $order->status)) {
                    $status = $order->status;
                }
                if (! $restoring && ! OrderStatus::canMove($order->status, $status)) {
                    $order->forceFill(['sync_warning' => "Bỏ qua chuyển trạng thái {$order->status} → {$status} (không hợp lệ theo quy trình)."])->save();

                    return ['result' => self::IGNORED_TRANSITION, 'order' => $order];
                }
            }

            $from = $isNew ? null : $order->status;
            $wasOpen = $from !== null && OrderStatus::policy($from) === OrderStatus::RESERVE;
            $this->fill($order, $data, $status);
            $order->forceFill(['deleted_on_channel_at' => $data->rawStatus === 'trash' ? ($order->deleted_on_channel_at ?? now()) : null]);
            if ($restoring) {
                $order->forceFill(['cancelled_at' => null, 'cancel_reason' => null]);
            }
            $order->save();
            $this->upsertItems($account, $order, $data->items, $data->refunds !== null, rebuild: $restoring
                // Only the seller's own store edits orders; a snapshot without lines never empties an order.
                || ($data->ownsCustomer && $data->items !== [] && $wasOpen && OrderStatus::policy($status) === OrderStatus::RESERVE
                    && $this->linesChanged($order, $data->items, $data->refunds !== null)));
            if ($data->refunds) {
                $this->recordRefunds($order, $data->refunds);
            }

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
            // Marketplaces that send the full number can be searched by it; a masked one gives no lookup key.
            'buyer_phone_hash' => str_contains((string) $data->buyerPhone, '*') ? null : PhoneNumber::hash(PhoneNumber::normalize($data->buyerPhone)),
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
        ]);

        if ($data->ownsCustomer) {
            $this->fillStoreDetails($order, $data);
        } elseif ($order->customer_id === null && filled($data->buyerAccountId)) {
            $order->customer_id = $this->marketplaceBuyer($order, $data)?->id;
        }
        $order->save();
    }

    /**
     * A marketplace buyer known by their account on that shop (not by the masked phone): the next order of the same
     * account lands on the same card. The card gets only a name; the buyer's phone and address stay on the order,
     * as the marketplace gives them for delivering it.
     */
    private function marketplaceBuyer(Order $order, OrderData $data): ?Customer
    {
        $name = filled($data->buyerName) && ! str_contains((string) $data->buyerName, '*') ? $data->buyerName : $data->buyerAccountName;

        return app(CustomerResolver::class)->resolve(
            ['name' => filled($name) ? mb_substr((string) $name, 0, 150) : null],
            ['source' => $order->marketplace, 'account_id' => $order->marketplace_account_id, 'external_id' => $data->buyerAccountId, 'label' => $data->buyerAccountName],
            $order->marketplace,
        );
    }

    private function beforeCutoff(MarketplaceAccount $account, OrderData $data): bool
    {
        return $account->orders_since !== null && $data->placedAt !== null && $data->placedAt->lessThan($account->orders_since);
    }

    /**
     * The seller's own store (WooCommerce): full contact, addresses, coupons, tax, fees, payment, and the customer
     * card. Marketplaces never reach this (their buyers' data is not the seller's to keep).
     */
    private function fillStoreDetails(Order $order, OrderData $data): void
    {
        $order->forceFill([
            'external_number' => $data->externalNumber,
            'buyer_phone' => $data->buyerPhone,
            'buyer_email' => $data->buyerEmail,
            'addresses' => $data->addresses,
            'external_customer_id' => $data->externalCustomerId,
            'tax_total' => $data->taxTotal,
            'fee_total' => $data->feeTotal,
            'coupon_codes' => $data->couponCodes ?: null,
            'transaction_id' => $data->transactionId,
        ]);
        if ($data->refundedTotal !== null) {
            $order->forceFill(['refunded_amount' => $data->refundedTotal]);
        }
        // The store only proves "paid" (online payment); it never proves "not paid yet" for money ECSO recorded.
        if ($data->paymentStatus === Order::PAYMENT_PAID) {
            $order->forceFill(['payment_status' => Order::PAYMENT_PAID, 'paid_amount' => $data->total]);
        } elseif ($order->payment_status === null) {
            $order->payment_status = Order::PAYMENT_UNPAID;
        }

        $phone = PhoneNumber::normalize($data->buyerPhone);
        if ($order->customer_id === null && $phone !== null && PhoneNumber::isValid($phone)) {
            $order->customer_id = $this->customer($data)?->id;
        }
    }

    /** Finds the customer by phone or creates one; two orders of a new buyer at once both end on the same card. */
    private function customer(OrderData $data): ?Customer
    {
        $fields = ['name' => mb_substr($data->buyerName ?? 'Khách website', 0, 150), 'phone' => $data->buyerPhone, 'email' => $data->buyerEmail, 'address' => $data->shippingAddress];
        try {
            return $this->customers->findOrCreate($fields);
        } catch (UniqueConstraintViolationException) {
            return $this->customers->findByPhone($data->buyerPhone);
        } catch (ValidationException) {
            return null; // a phone that is not a valid number: the order keeps it, no customer card is made
        }
    }

    /**
     * @param  array<int, OrderItemData>  $items
     */
    private function upsertItems(MarketplaceAccount $account, Order $order, array $items, bool $withRefunds = false, bool $rebuild = false): void
    {
        if ($rebuild) {
            // The store changed the lines of an order that has not shipped (or restored a deleted one): give back
            // what was held and take the lines again, so the stock held follows the new quantities. New item rows
            // get new reservation keys; the old reservations stay in the ledger as released.
            $ids = $order->items()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->ledger->releaseAll(Order::STOCK_REFERENCE, $ids, "Đơn {$order->external_order_id} đổi trên website");
            $order->items()->delete();
        } elseif ($items !== [] && ! $order->wasRecentlyCreated && OrderStatus::isShipped($order->status) && $this->linesChanged($order, $items, false)) {
            $order->forceFill(['sync_warning' => 'Đơn đã sửa số lượng hoặc dòng hàng trên website sau khi ra khỏi kho; ECSO giữ số lượng đã xuất, cần kiểm tra.'])->save();
        }
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
                'tax' => $data->tax,
            ]);
            if ($withRefunds || ! $item->exists) {
                $item->refunded_quantity = $data->refundedQuantity;
            }

            // The internal SKU is fixed once set, because stock may already be held against it.
            if ($item->product_variant_id === null && $listing?->isMapped()) {
                $item->product_variant_id = $listing->product_variant_id;
            }
            $item->mapping_status = $item->product_variant_id ? ListingVariant::MAPPED : ListingVariant::UNMAPPED;
            $item->save();
        }
    }

    /**
     * Whether the store's lines differ from the order's (a line added, removed, or its quantity changed).
     *
     * @param  array<int, OrderItemData>  $items
     */
    private function linesChanged(Order $order, array $items, bool $withRefunds): bool
    {
        // Units refunded on the store before shipping are not to be shipped: they count as removed.
        $now = collect($items)->mapWithKeys(fn (OrderItemData $i) => [$i->externalItemId => $i->quantity - ($withRefunds ? $i->refundedQuantity : 0)])->sortKeys()->all();
        $before = $order->items()->get(['external_item_id', 'quantity', 'refunded_quantity'])
            ->mapWithKeys(fn (OrderItem $i) => [$i->external_item_id => $i->quantity - ($withRefunds ? $i->refunded_quantity : 0)])->sortKeys()->all();

        return $now != $before;
    }

    /**
     * Keeps each store refund once. A refund of lines that already left the warehouse opens a return request for
     * them: stock comes back only when the parcel is received and inspected, never from the refund itself.
     *
     * @param  list<array{id: string, amount: string, reason: string|null, refunded_at: CarbonImmutable|null, lines: list<array{external_item_id: string, quantity: int, amount: string}>}>  $refunds
     */
    private function recordRefunds(Order $order, array $refunds): void
    {
        $known = OrderRefund::query()->where('order_id', $order->id)->pluck('external_refund_id')->all();
        $items = $order->items()->get()->keyBy('external_item_id');
        $shipped = OrderStatus::isShipped($order->status);

        foreach ($refunds as $r) {
            if (in_array($r['id'], $known, true)) {
                continue;
            }
            $lines = array_map(fn ($l) => $l + ['order_item_id' => $items->get($l['external_item_id'])?->id], $r['lines']);
            $refund = OrderRefund::query()->create([
                'order_id' => $order->id, 'external_refund_id' => $r['id'], 'amount' => $r['amount'], 'reason' => $r['reason'],
                'lines' => $lines, 'refunded_at' => $r['refunded_at'] ?? now(),
            ]);

            $returnLines = array_values(array_filter(array_map(fn ($l) => $l['order_item_id'] ? ['order_item_id' => $l['order_item_id'], 'quantity' => $l['quantity']] : null, $lines)));
            if (! $shipped || $returnLines === []) {
                continue;
            }
            try {
                $return = $this->returns->create($order, [
                    'type' => OrderReturn::TYPE_RETURN, 'reason' => 'Hoàn tiền trên website'.($r['reason'] ? ": {$r['reason']}" : ''),
                    'refund_amount' => $r['amount'], 'lines' => $returnLines, 'source_key' => "store-refund:{$r['id']}",
                ]);
                $refund->forceFill(['order_return_id' => $return->id])->save();
            } catch (ValidationException $e) {
                // More units refunded than are left to return (already returned): the refund is kept, the user checks.
                $order->forceFill(['sync_warning' => 'Hoàn tiền trên website: '.collect($e->errors())->flatten()->first()])->save();
            }
        }
    }

    /**
     * Moves an order in ECSO only: an action on a one-way store (nothing is written to the store), or an order
     * whose raw status got a mapping. Records the history and applies the stock policy, like a store update.
     */
    public function applyLocal(Order $order, string $status, string $source, ?int $actorId = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $status, $source, $actorId, $reason) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status === $status) {
                return $order;
            }
            if (! OrderStatus::canMove($order->status, $status)) {
                throw ValidationException::withMessages(['action' => 'Đơn đang ở trạng thái không cho phép thao tác này.']);
            }
            OrderStatusHistory::query()->create([
                'order_id' => $order->id, 'from_status' => $order->status, 'to_status' => $status,
                'raw_status' => $order->raw_status, 'source' => $source, 'actor_id' => $actorId, 'occurred_at' => now(),
            ]);
            $order->forceFill([
                'status' => $status,
                'handed_over_at' => OrderStatus::isShipped($status) ? ($order->handed_over_at ?? now()) : $order->handed_over_at,
                'cancelled_at' => $status === OrderStatus::CANCELLED ? ($order->cancelled_at ?? now()) : $order->cancelled_at,
                'cancel_reason' => $status === OrderStatus::CANCELLED ? ($reason ?? $order->cancel_reason) : $order->cancel_reason,
                'sync_warning' => null,
            ])->save();
            $this->stock->apply($order);

            return $order;
        }, 5);
    }

    /**
     * The order no longer exists on the store (deleted for good). ECSO never deletes it: the order is flagged, and if
     * it had not left the warehouse it is cancelled so its held stock is free again. Returns null when ECSO does
     * not have the order. Must run inside the tenant context of $account.
     *
     * @return array{result: string, order: Order}|null
     */
    public function markDeletedOnChannel(MarketplaceAccount $account, string $externalOrderId, string $source, ?int $webhookEventId = null): ?array
    {
        return DB::transaction(function () use ($account, $externalOrderId, $source, $webhookEventId) {
            $id = Order::query()->where('marketplace_account_id', $account->id)->where('external_order_id', $externalOrderId)->value('id');
            if (! $id) {
                return null;
            }
            $order = Order::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $order->deleted_on_channel_at ??= now();

            if (OrderStatus::policy($order->status) === OrderStatus::RESERVE || $order->status === OrderStatus::UNKNOWN_STATUS) {
                OrderStatusHistory::query()->create([
                    'order_id' => $order->id, 'from_status' => $order->status, 'to_status' => OrderStatus::CANCELLED,
                    'raw_status' => 'deleted', 'source' => $source, 'webhook_event_id' => $webhookEventId, 'occurred_at' => now(),
                ]);
                $order->forceFill(['status' => OrderStatus::CANCELLED, 'cancelled_at' => $order->cancelled_at ?? now(), 'cancel_reason' => 'Đơn đã bị xóa trên website']);
            } elseif (! in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::RETURNED], true)) {
                $order->sync_warning = 'Đơn đã bị xóa trên website sau khi ra khỏi kho; ECSO giữ nguyên đơn.';
            }
            $order->save();
            $this->stock->apply($order);

            return ['result' => self::DELETED, 'order' => $order];
        }, 5);
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
