<?php

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ReservationLedger;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Marketplace\Connectors\Shopee\ShopeeConnector;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Support\DeliveryType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Support\PhoneNumber;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** Filters of the order list (ORDER-LIFECYCLE §7). Every filter value is resolved inside the tenant scope. */
class OrderQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    public function build(array $filters, ?int $userId, bool $withTab = true): Builder
    {
        $query = self::visible(Order::query());

        if ($withTab && ($tab = $filters['tab'] ?? null) && isset(OrderStatus::TABS[$tab])) {
            $query->whereIn('status', OrderStatus::TABS[$tab]);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($account = $filters['account'] ?? null) {
            $query->where('marketplace_account_id', MarketplaceAccount::query()->where('ulid', $account)->value('id') ?? 0);
        }
        if ($channel = $filters['channel'] ?? null) {
            $query->where('sales_channel_id', SalesChannel::query()->where('ulid', $channel)->value('id') ?? 0);
        }
        $accounts = array_values(array_filter((array) ($filters['accounts'] ?? [])));
        $channels = array_values(array_filter((array) ($filters['channels'] ?? [])));
        if ($accounts || $channels) {
            $accountIds = $accounts ? MarketplaceAccount::query()->whereIn('ulid', $accounts)->pluck('id')->all() : [];
            $channelIds = $channels ? SalesChannel::query()->whereIn('ulid', $channels)->pluck('id')->all() : [];
            $query->where(fn (Builder $w) => $w->whereIn('marketplace_account_id', $accountIds ?: [0])->orWhereIn('sales_channel_id', $channelIds ?: [0]));
        }
        if ($warehouse = $filters['warehouse'] ?? null) {
            $query->where('warehouse_id', Warehouse::query()->where('ulid', $warehouse)->value('id') ?? 0);
        }
        if ($customer = $filters['customer'] ?? null) {
            $query->where('customer_id', Customer::query()->where('ulid', $customer)->value('id') ?? 0);
        }
        if ($source = $filters['source'] ?? null) {
            match ($source) {
                'manual' => $query->where('marketplace', Order::SOURCE_MANUAL),
                // Orders taken from the previous software (Chuyển dữ liệu), e.g. 'sapo'; 'new' = everything else.
                'sapo' => $query->where('imported_from', 'sapo'),
                'new' => $query->whereNull('imported_from'),
                default => $query->where('marketplace', '!=', Order::SOURCE_MANUAL),
            };
        }
        if ($paymentStatus = $filters['payment_status'] ?? null) {
            $query->where('payment_status', $paymentStatus);
        }
        if ($marketplace = $filters['marketplace'] ?? null) {
            $query->where('marketplace', $marketplace);
        }
        if ($delivery = $filters['delivery'] ?? null) {
            $query->where('delivery_type', $delivery);
        }
        if ($carrier = $filters['carrier'] ?? null) {
            $query->where('shipping_carrier', $carrier);
        }
        if ($from = $filters['from'] ?? null) {
            $query->where('placed_at', '>=', ReportRange::date($from));
        }
        if ($to = $filters['to'] ?? null) {
            $query->where('placed_at', '<', ReportRange::date($to)->addDay());
        }
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $phoneHash = PhoneNumber::hash(PhoneNumber::normalize($q)) ?? '-';
            // Mã đơn (SON50892), the marketplace / Woo number, a typed reference, the old SO-… number, the tracking code.
            $query->where(fn (Builder $w) => $w->where('code', $q)->orWhere('code', 'like', "%{$q}%")
                ->orWhere('external_order_id', 'like', "%{$q}%")
                ->orWhere('reference', 'like', "%{$q}%")
                ->orWhere('external_number', $q)
                ->orWhere('external_number', ltrim($q, '#'))
                // Mã vận đơn: whole, or a part of at least 5 characters (the end read off a parcel).
                ->orWhere('tracking_number', mb_strlen($q) >= 5 ? 'like' : '=', mb_strlen($q) >= 5 ? "%{$q}%" : $q)
                ->orWhere('buyer_phone_hash', $phoneHash)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%")->orWhere('phone_hash', $phoneHash))
                ->orWhereHas('items', fn ($i) => $i->where('seller_sku', $q)->orWhere('name', 'like', "%{$q}%")));
        }
        if ($sku = $filters['sku'] ?? null) {
            $variantId = ProductVariant::query()->where('ulid', $sku)->value('id') ?? 0;
            $query->whereHas('items', fn ($i) => $i->where('product_variant_id', $variantId));
        }
        if ($tag = $filters['tag'] ?? null) {
            // MariaDB's JSON_CONTAINS misses accented tags stored as \u escapes ("Kh\u00e1ch VIP"); JSON_SEARCH decodes them.
            in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? $query->whereRaw("JSON_SEARCH(tags, 'one', ?) IS NOT NULL", [addcslashes($tag, '%_\\')])
                : $query->whereJsonContains('tags', $tag);
        }
        if ($from = $filters['deliver_from'] ?? null) {
            $query->whereDate('deliver_on', '>=', $from);
        }
        if ($to = $filters['deliver_to'] ?? null) {
            $query->whereDate('deliver_on', '<=', $to);
        }
        if ($assignee = $filters['assignee'] ?? null) {
            match ($assignee) {
                'me' => $query->where('assigned_to', $userId),
                'none' => $query->whereNull('assigned_to'),
                default => $query->where('assigned_to', User::query()->where('ulid', $assignee)->value('id') ?? 0),
            };
        }
        foreach ((array) ($filters['flags'] ?? []) as $flag) {
            match ($flag) {
                'shortage' => $query->where('has_shortage', true),
                // "Hàng đặt": open orders waiting for goods bought from the supplier.
                'awaiting_purchase' => $query->where('awaiting_purchase', true)->whereIn('status', OrderStatus::OPEN),
                'unmapped' => $query->where('has_unmapped', true),
                'overdue' => $query->whereIn('status', OrderStatus::OPEN)->where('ship_by_at', '<', now()),
                'due_soon' => $query->whereIn('status', OrderStatus::OPEN)->whereBetween('ship_by_at', [now(), now()->addHours(config('ecso.orders.due_soon_hours'))]),
                'warning' => $query->whereNotNull('sync_warning'),
                'not_printed' => $query->whereNull('packing_slip_printed_at'),
                // Shopee / TikTok orders: "Chuẩn bị hàng" not done yet; arranged but the marketplace label never printed.
                // Orders Shopee is still checking cannot be prepared yet (Order::underMarketplaceReview): left out.
                'not_arranged' => $query->whereIn('marketplace', MarketplaceShipping::marketplaces())->where('status', OrderStatus::READY_TO_PACK)
                    ->where(fn (Builder $q) => $q->where('marketplace', '!=', ShopeeConnector::MARKETPLACE)->orWhereNull('raw_status')->orWhere('raw_status', '!=', ShopeeConnector::REVIEW_STATUS))
                    // Just prepared: the marketplace moves it on in a few seconds, it is no longer waiting for anyone.
                    ->where(fn (Builder $q) => $q->whereNull('arranged_at')->orWhere('arranged_at', '<=', now()->subMinutes(Order::ARRANGING_MINUTES))),
                'label_unprinted' => $query->whereIn('marketplace', MarketplaceShipping::marketplaces())->whereIn('status', MarketplaceShipping::LABEL_FROM)->where('label_print_count', 0),
                // Orders taken from the previous software are settled there (what is owed came as opening debt).
                'payment_due' => $query->where('marketplace', Order::SOURCE_MANUAL)->whereNull('imported_from')->where('status', '!=', OrderStatus::CANCELLED)
                    ->where('payment_status', '!=', Order::PAYMENT_PAID),
                default => null,
            };
        }

        return $query;
    }

    /**
     * Orders the signed-in member may see: all of them with orders.view.all, otherwise only those they created or
     * were assigned (a cashier who should not browse colleagues' sales).
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function visible(Builder $query): Builder
    {
        $context = app(TenantContext::class);
        if (! $context->has() || $context->can('orders.view.all') || ! Auth::check()) {
            return $query;
        }
        $me = Auth::id();

        return $query->where(fn (Builder $w) => $w->where('created_by', $me)->orWhere('assigned_to', $me));
    }

    /**
     * Order count per tab for the current filters (without the tab filter itself).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function tabCounts(array $filters, ?int $userId): array
    {
        $byStatus = $this->build($filters, $userId, withTab: false)->toBase()
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $counts = ['all' => (int) $byStatus->sum()];
        foreach (OrderStatus::TABS as $tab => $statuses) {
            $counts[$tab] = (int) collect($statuses)->sum(fn ($s) => $byStatus[$s] ?? 0);
        }

        return $counts;
    }

    /**
     * "Kênh bán" picker: orders per shop and per own sales channel under the current filters (tab included, the
     * channel filters themselves left out so every row keeps its count).
     *
     * @param  array<string, mixed>  $filters
     * @return array{accounts: array<string, int>, channels: array<string, int>}
     */
    public function sourceCounts(array $filters, ?int $userId): array
    {
        $rows = $this->build([...$filters, 'accounts' => null, 'channels' => null, 'account' => null, 'channel' => null, 'marketplace' => null, 'source' => null], $userId)->toBase()
            ->selectRaw('marketplace_account_id, sales_channel_id, count(*) as c')->groupBy('marketplace_account_id', 'sales_channel_id')->get();
        $accounts = MarketplaceAccount::query()->whereIn('id', $rows->pluck('marketplace_account_id')->filter())->pluck('ulid', 'id');
        $channels = SalesChannel::query()->whereIn('id', $rows->pluck('sales_channel_id')->filter())->pluck('ulid', 'id');
        $out = ['accounts' => [], 'channels' => []];
        foreach ($rows as $r) {
            if ($r->marketplace_account_id && isset($accounts[$r->marketplace_account_id])) {
                $key = $accounts[$r->marketplace_account_id];
                $out['accounts'][$key] = ($out['accounts'][$key] ?? 0) + (int) $r->c;
            } elseif ($r->sales_channel_id && isset($channels[$r->sales_channel_id])) {
                $key = $channels[$r->sales_channel_id];
                $out['channels'][$key] = ($out['channels'][$key] ?? 0) + (int) $r->c;
            }
        }

        return $out;
    }

    /**
     * "Kiểu giao" row above the list: marketplace orders per delivery group and per carrier under the current filters
     * (tab included, the delivery / carrier filters themselves left out so every chip keeps its count).
     *
     * @param  array<string, mixed>  $filters
     * @return array{types: array<string, int>, carriers: list<array{name: string, count: int}>}
     */
    public function deliveryCounts(array $filters, ?int $userId): array
    {
        $base = $this->build([...$filters, 'delivery' => null, 'carrier' => null], $userId)->where('marketplace', '!=', Order::SOURCE_MANUAL);
        $types = (clone $base)->toBase()->whereNotNull('delivery_type')->selectRaw('delivery_type, count(*) as c')->groupBy('delivery_type')->pluck('c', 'delivery_type');
        $carriers = $this->build([...$filters, 'carrier' => null], $userId)->where('marketplace', '!=', Order::SOURCE_MANUAL)->toBase()
            ->whereNotNull('shipping_carrier')->selectRaw('shipping_carrier, count(*) as c')->groupBy('shipping_carrier')->orderByDesc('c')->limit(20)->get();

        return [
            'types' => collect(array_keys(DeliveryType::LABELS))->mapWithKeys(fn ($t) => [$t => (int) ($types[$t] ?? 0)])->all(),
            'carriers' => $carriers->map(fn ($r) => ['name' => (string) $r->shipping_carrier, 'count' => (int) $r->c])->values()->all(),
        ];
    }

    /** Most orders "Chuẩn bị hàng" takes in one go (same cap as the bulk actions). */
    public const ARRANGE_MAX = 200;

    /**
     * Shopee / TikTok orders waiting for "Chuẩn bị hàng" under the current filters (tab and quick filters aside):
     * how urgent they are, which have the goods, and the same orders grouped by product so a whole product can be
     * picked and prepared at once. Orders come soonest deadline first.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function toArrange(array $filters, ?int $userId): array
    {
        $query = $this->build([...$filters, 'flags' => ['not_arranged']], $userId, withTab: false);
        $count = (clone $query)->count();
        $orders = $query->with(['items.variant.product.images'])
            ->orderByRaw("delivery_type <=> 'express' DESC")->orderByRaw('ship_by_at IS NULL')->orderBy('ship_by_at')->orderBy('id')
            ->limit(self::ARRANGE_MAX)->get();

        $items = $orders->flatMap(fn (Order $o) => $o->items)->filter(fn (OrderItem $i) => $i->mapping_status !== OrderItem::SERVICE);
        $snapshot = app(ReservationLedger::class)->snapshot(Order::STOCK_REFERENCE, $items->pluck('id')->all());
        $variantIds = $items->pluck('product_variant_id')->filter()->unique()->values()->all();
        $onHand = $variantIds === [] ? [] : app(StockReader::class)->onHand(array_map('intval', $variantIds));

        $products = [];
        $rows = [];
        foreach ($orders as $order) {
            $keys = [];
            foreach ($order->items as $item) {
                if ($item->mapping_status === OrderItem::SERVICE) {
                    continue;
                }
                $quantity = max(0, $item->quantity - (int) $item->refunded_quantity);
                $variant = $item->variant;
                $variation = $variant && ! filled($variant->name) && filled($item->variant_name) ? (string) $item->variant_name : null;
                $key = $variant ? 'v:'.$variant->ulid.($variation !== null ? '|'.mb_strtolower($variation) : '') : 'n:'.mb_strtolower(trim((string) ($item->seller_sku ?: $item->name.'|'.$item->variant_name)));
                $products[$key] ??= [
                    'key' => $key,
                    'name' => $variant?->product->name ?? $item->name,
                    // The shop's own variant name, else the marketplace's variation ("128GB") so the right item is picked.
                    'variant_name' => ($variant ? $variant->name : null) ?: $item->variant_name,
                    'sku' => $variant->sku ?? $item->seller_sku,
                    'image' => $variant?->product->images->first()?->url(),
                    'mapped' => $variant !== null,
                    'on_hand' => $variant ? (int) ($onHand[$variant->id] ?? 0) : null,
                    'quantity' => 0,
                    'short' => false,
                    'orders' => [],
                ];
                $products[$key]['quantity'] += $quantity;
                $products[$key]['short'] = $products[$key]['short'] || (($snapshot[$item->id]['shortage'] ?? 0) > 0);
                if (! in_array($order->ulid, $products[$key]['orders'], true)) {
                    $products[$key]['orders'][] = $order->ulid;
                }
                $keys[$key] = ($keys[$key] ?? 0) + $quantity;
            }
            $rows[] = [
                'id' => $order->ulid,
                'external_order_id' => $order->external_order_id, 'code' => $order->code,
                'status' => $order->status,
                'source' => 'marketplace',
                'marketplace_shipping' => true,
                'ship_by_at' => $order->ship_by_at?->toIso8601String(),
                'delivery_type' => $order->delivery_type,
                'shortage' => (bool) $order->has_shortage || (bool) $order->awaiting_purchase,
                // Units of each product in this order, by product key.
                'products' => (object) $keys,
            ];
        }
        usort($products, fn ($a, $b) => [count($b['orders']), $b['quantity'], $a['name']] <=> [count($a['orders']), $a['quantity'], $b['name']]);

        $soon = now()->addHours(3);
        $endOfDay = now(ReportRange::timezone())->endOfDay();
        $due = fn ($limit) => $orders->filter(fn (Order $o) => $o->ship_by_at !== null && $o->ship_by_at->lte($limit))->count();

        return [
            'count' => $count,
            'urgent' => $due($soon),
            'today' => $due($endOfDay),
            'ready' => collect($rows)->where('shortage', false)->count(),
            'orders' => $rows,
            'products' => $products,
        ];
    }

    /**
     * "Orders to handle" strip above the list: count and amount of each queue that waits for someone.
     * Payment due only counts manual orders (marketplaces collect the money themselves).
     *
     * @return array<string, array{count: int, amount: string}>
     */
    public function pipeline(): array
    {
        $byStatus = self::visible(Order::query())->toBase()
            ->whereIn('status', [...OrderStatus::OPEN, OrderStatus::HANDED_OVER, OrderStatus::IN_TRANSIT, OrderStatus::FAILED_DELIVERY])
            ->selectRaw('status, count(*) as c, COALESCE(SUM(total), 0) as amount')->groupBy('status')->get()->keyBy('status');
        $sum = fn (array $statuses) => [
            'count' => (int) collect($statuses)->sum(fn ($s) => $byStatus[$s]->c ?? 0),
            'amount' => collect($statuses)->reduce(fn (string $t, $s) => bcadd($t, (string) ($byStatus[$s]->amount ?? 0), 2), '0.00'),
        ];

        $due = self::visible(Order::query())->toBase()->where('marketplace', Order::SOURCE_MANUAL)->whereNull('imported_from')->where('status', '!=', OrderStatus::CANCELLED)
            ->where('payment_status', '!=', Order::PAYMENT_PAID)
            ->selectRaw('count(*) as c, COALESCE(SUM(GREATEST(total - paid_amount, 0)), 0) as amount')->first();

        return [
            'to_confirm' => $sum([OrderStatus::PENDING_CONFIRMATION]),
            'payment_due' => ['count' => (int) ($due->c ?? 0), 'amount' => bcadd((string) ($due->amount ?? 0), '0', 2)],
            'to_pack' => $sum([OrderStatus::READY_TO_PACK]),
            'to_handover' => $sum([OrderStatus::PACKED]),
            'shipping' => $sum([OrderStatus::HANDED_OVER, OrderStatus::IN_TRANSIT]),
            'failed_delivery' => $sum([OrderStatus::FAILED_DELIVERY]),
        ];
    }
}
