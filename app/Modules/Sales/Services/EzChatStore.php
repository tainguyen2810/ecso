<?php

namespace App\Modules\Sales\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Marketplace\Connectors\EzChat\EzChatGateway;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderExporter;
use App\Modules\Sales\Models\EzChatLink;
use App\Modules\Sales\Models\EzChatLinkOrder;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Support\PhoneNumber;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Đợt 7 "Nối với EzChat". A store links to one EzChat workspace (grant + exchange, like the shop share the other way
 * round); EzChat then treats this store as the workspace's website store ("Kết nối website", kind banhang): it reads
 * the products with their real price and stock for its assistant, checks stock before an order, and sends every order
 * closed in chat here, where it holds stock in the warehouse of the link's sales channel.
 *
 * The store API keeps EzChat's shape for a shop's own API (see EzChatStoreController): GET /products, GET /stock,
 * POST /orders, POST /orders/{number}/cancel, GET /orders?phone=, GET /orders/lookup.
 */
class EzChatStore
{
    public const CODE_TTL = 300;

    public const CHANNEL_NAME = 'Chat EzChat';

    private const PAGE = 100;

    public function __construct(private readonly ManualOrderService $orders, private readonly StockReader $stock) {}

    /** The address EzChat returns to after "Cho phép", with a one-time code. Runs in the seller's tenant. */
    public function grant(string $state, string $redirect): string
    {
        if (! EzChatGateway::configured()) {
            throw ValidationException::withMessages(['redirect' => 'Máy chủ chưa cấu hình kết nối EzChat.']);
        }
        $base = (string) config('marketplaces.ezchat.url');
        $host = parse_url($redirect, PHP_URL_HOST);
        if (! is_string($host) || $host !== parse_url($base, PHP_URL_HOST) || parse_url($redirect, PHP_URL_SCHEME) !== parse_url($base, PHP_URL_SCHEME)) {
            throw ValidationException::withMessages(['redirect' => 'Địa chỉ quay lại không phải EzChat.']);
        }
        $code = Str::random(40);
        Cache::put("ezchat-link:code:{$code}", ['tenant' => app(TenantContext::class)->id(), 'user' => Auth::id()], self::CODE_TTL);

        return $redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query(['code' => $code, 'state' => $state]);
    }

    /**
     * EzChat trades the code for the store API address and its secret (server to server). A new link replaces the
     * store's earlier one, so the old secret stops working.
     *
     * @param  array{id?: string, name?: string}  $workspace
     * @return array{url: string, secret: string, store: array{name: string}}
     */
    public function exchange(string $code, array $workspace): array
    {
        $grant = Cache::pull("ezchat-link:code:{$code}");
        $tenant = is_array($grant) ? Tenant::query()->find($grant['tenant']) : null;
        if ($tenant === null) {
            throw ValidationException::withMessages(['code' => 'Mã liên kết đã hết hạn. Bấm Nối với EzChat Bán hàng lần nữa.']);
        }

        return TenantContext::run($tenant, function () use ($tenant, $grant, $workspace) {
            $secret = Str::random(48);
            $link = DB::transaction(function () use ($grant, $workspace, $secret) {
                $link = EzChatLink::query()->lockForUpdate()->first() ?? new EzChatLink;
                $link->forceFill([
                    'workspace_id' => Str::limit((string) ($workspace['id'] ?? ''), 64, ''),
                    'workspace_name' => Str::limit((string) ($workspace['name'] ?? ''), 150, ''),
                    'secret' => $secret,
                    'sales_channel_id' => $link->sales_channel_id ?? $this->channel()->id,
                    'linked_by' => $grant['user'] ?? null,
                ])->save();

                return $link;
            });

            return ['url' => url('/api/v1/ezchat-store/'.$link->ulid), 'secret' => $secret, 'store' => ['name' => (string) $tenant->name]];
        });
    }

    /** The sales channel chat orders go to: "Chat EzChat", made once. */
    public function channel(): SalesChannel
    {
        $channel = SalesChannel::query()->firstOrCreate(['name' => self::CHANNEL_NAME], ['type' => 'other', 'is_active' => true]);
        if (! $channel->is_active) {
            $channel->forceFill(['is_active' => true])->save();
        }

        return $channel;
    }

    /** The warehouse that gives the goods of chat orders: the channel's, else the default one. */
    public function warehouse(EzChatLink $link): ?Warehouse
    {
        $id = $link->channel?->warehouse_id;

        return ($id ? Warehouse::query()->find($id) : null)
            ?? Warehouse::query()->where('is_default', true)->first()
            ?? Warehouse::query()->orderBy('id')->first();
    }

    /**
     * One page of the products on sale, in WooCommerce's REST shape (EzChat reads WooCommerce stores the same way),
     * each with its SKUs as "variations" and the stock it can sell from the link's warehouse.
     *
     * @return list<array<string, mixed>>
     */
    public function products(EzChatLink $link, int $page): array
    {
        $products = Product::query()->where('status', Product::STATUS_ACTIVE)
            ->with(['variants' => fn ($q) => $q->where('status', Product::STATUS_ACTIVE)->with('image'), 'images'])
            ->orderBy('id')->forPage(max(1, $page), self::PAGE)->get()
            ->filter(fn (Product $p) => $p->variants->isNotEmpty());
        $warehouse = $this->warehouse($link);
        $ids = $products->flatMap(fn (Product $p) => $p->variants->pluck('id'))->map(fn ($id) => (int) $id)->all();
        $qty = $warehouse && $ids ? $this->stock->sellable([$warehouse->id], $ids) : [];

        return $products->map(function (Product $p) use ($qty) {
            $variants = $p->variants;
            $single = $variants->count() === 1;
            $total = (int) $variants->sum(fn (ProductVariant $v) => max(0, $qty[$v->id] ?? 0));
            $prices = $variants->map(fn (ProductVariant $v) => (float) $v->ref_price)->filter(fn ($x) => $x > 0);
            $price = $single ? (float) $variants->first()->ref_price : (float) ($prices->min() ?? 0);
            $images = $p->images->map(fn (ProductImage $i) => ['src' => url($i->url())])->values()->all();

            return [
                'id' => $p->ulid,
                'name' => $p->name,
                'sku' => $single ? $variants->first()->sku : null,
                'type' => $single ? 'simple' : 'variable',
                'price' => $price > 0 ? (string) $price : '',
                'regular_price' => $price > 0 ? (string) $price : '',
                'manage_stock' => true,
                'stock_quantity' => $total,
                'stock_status' => $total > 0 ? 'instock' : 'outofstock',
                'images' => $images ?: array_filter([$this->variantImage($variants->first())]),
                'short_description' => Str::limit(trim(strip_tags((string) $p->description)), 1000, ''),
                'permalink' => null,
                'variations' => $single ? [] : $variants->map(fn (ProductVariant $v) => [
                    'id' => $v->ulid,
                    'sku' => $v->sku,
                    'price' => (float) $v->ref_price > 0 ? (string) (float) $v->ref_price : '',
                    'manage_stock' => true,
                    'stock_quantity' => max(0, $qty[$v->id] ?? 0),
                    'stock_status' => ($qty[$v->id] ?? 0) > 0 ? 'instock' : 'outofstock',
                    'attributes' => [['option' => $v->name ?: $v->sku]],
                    'image' => $this->variantImage($v),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /** @return array{src: string}|null */
    private function variantImage(?ProductVariant $v): ?array
    {
        return $v?->image ? ['src' => url($v->image->url())] : null;
    }

    /**
     * Stock of one product (its only SKU) or one of its SKUs, from the link's warehouse.
     *
     * @return array{inStock: bool, qty: int}
     */
    public function stockOf(EzChatLink $link, string $product, ?string $variant): array
    {
        $v = $this->variant($product, $variant);
        $warehouse = $this->warehouse($link);
        $qty = $v && $warehouse ? max(0, $this->stock->sellable([$warehouse->id], [(int) $v->id])[(int) $v->id] ?? 0) : 0;

        return ['inStock' => $v !== null && $v->isSellable() && $qty > 0, 'qty' => $qty];
    }

    /** The SKU a chat item names: its variant id, else the product's only active SKU, else a SKU code. */
    private function variant(?string $product, ?string $variant): ?ProductVariant
    {
        if (filled($variant) && ($v = ProductVariant::query()->with('product')->where('ulid', $variant)->first())) {
            return $v;
        }
        if (filled($product)) {
            $p = Product::query()->where('ulid', $product)->with(['variants' => fn ($q) => $q->where('status', Product::STATUS_ACTIVE)])->first();
            if ($p !== null) {
                return $p->variants->count() === 1 ? $p->variants->first() : null;
            }

            return ProductVariant::query()->with('product')->where('sku', $product)->first();
        }

        return null;
    }

    /**
     * An order closed in chat becomes an order of the "Chat EzChat" channel. Sent again with the same code, the order
     * made the first time is returned.
     *
     * @param  array<string, mixed>  $body  EzChat's order (StoreService.orderBody)
     * @return array{id: string}
     */
    public function createOrder(EzChatLink $link, array $body): array
    {
        $code = Str::limit(trim((string) ($body['code'] ?? '')), 40, '');
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'Thiếu mã đơn EzChat.']);
        }
        if ($done = EzChatLinkOrder::query()->where('code', $code)->with('order')->first()) {
            return ['id' => (string) $done->order?->external_order_id];
        }
        $items = array_slice(array_values(array_filter((array) ($body['items'] ?? []), 'is_array')), 0, 100);
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Đơn không có sản phẩm.']);
        }
        $lines = array_map(function (array $i) {
            $v = $this->variant(isset($i['sku']) ? (string) $i['sku'] : null, isset($i['variationId']) ? (string) $i['variationId'] : null);
            $name = trim((string) ($i['name'] ?? '')).(filled($i['variant'] ?? null) ? ' ('.trim((string) $i['variant']).')' : '');

            return [
                ...($v && $v->isSellable() ? ['variant_id' => $v->ulid] : ['name' => Str::limit($name !== '' ? $name : 'Sản phẩm', 255, '')]),
                'quantity' => max(1, min(100000, (int) ($i['qty'] ?? 1))),
                'unit_price' => max(0, (float) ($i['price'] ?? 0)),
            ];
        }, $items);
        $customer = (array) ($body['customer'] ?? []);
        $name = Str::limit(trim((string) ($customer['name'] ?? '')), 150, '');
        $phone = Str::limit(trim((string) ($customer['phone'] ?? '')), 20, '');
        $address = Str::limit(trim((string) ($customer['address'] ?? '')), 500, '');
        $note = trim('Đơn chốt trong chat EzChat '.$code.'. '.trim((string) ($body['note'] ?? '')));

        return DB::transaction(function () use ($link, $code, $lines, $name, $phone, $address, $note, $body) {
            $channel = $link->channel ?? $this->channel();
            $order = $this->orders->create([
                'channel_id' => $channel->ulid,
                'stage' => $link->order_stage === 'confirmed' ? 'confirmed' : 'pending',
                'lines' => $lines,
                ...($name !== '' ? ['customer' => ['name' => $name, 'phone' => $phone ?: null, 'address' => $address ?: null]] : []),
                'buyer_name' => $name ?: null,
                'buyer_phone' => $phone ?: null,
                'shipping_address' => $address ?: null,
                'shipping_fee' => max(0, (float) ($body['shippingFee'] ?? 0)),
                'discount' => max(0, (float) ($body['discount'] ?? 0)),
                // The price agreed in chat stands: the store's automatic promotions are not applied again.
                'promotions_off' => true,
                'is_cod' => ($body['payMethod'] ?? 'cod') !== 'bank',
                'tags' => ['EzChat'],
                'note' => Str::limit($note, 500, ''),
            ]);
            EzChatLinkOrder::query()->create(['code' => $code, 'order_id' => $order->id]);
            $link->forceFill(['last_used_at' => now()])->save();

            return ['id' => (string) $order->external_order_id];
        });
    }

    /** The customer cancelled in chat: the order is cancelled here while it has not left the warehouse. */
    public function cancel(string $number): bool
    {
        $order = $this->find($number);
        if ($order === null) {
            return false;
        }
        if (in_array($order->status, OrderStatus::OPEN, true)) {
            $this->orders->transition($order, 'cancel', ['reason' => 'Khách hủy trong chat EzChat']);

            return true;
        }

        return $order->status === OrderStatus::CANCELLED;
    }

    /** An order by its number here, or by the EzChat order code it came from. */
    private function find(string $number): ?Order
    {
        $number = trim($number, " #\t");
        if ($number === '') {
            return null;
        }

        return Order::query()->where('external_order_id', $number)->first()
            ?? EzChatLinkOrder::query()->where('code', $number)->first()?->order;
    }

    /**
     * "Tra đơn": the order, only when the phone it was placed with matches (or any phone, when EzChat says so).
     *
     * @return array{found: bool, order?: array<string, mixed>}
     */
    public function lookup(string $number, ?string $phone, bool $any): array
    {
        $order = $this->find($number);
        $hash = PhoneNumber::hash(PhoneNumber::normalize($phone));
        if ($order === null || (! $any && ($hash === null || ! hash_equals((string) $order->buyer_phone_hash, $hash)))) {
            return ['found' => false];
        }
        $order->loadMissing('items');

        return ['found' => true, 'order' => [
            'number' => $order->external_order_id,
            'status' => $order->status,
            'statusName' => OrderExporter::STATUS[$order->status] ?? $order->status,
            'date' => $order->placed_at?->toIso8601String(),
            'items' => $order->items->take(10)->map(fn ($i) => ['name' => $i->name, 'qty' => (int) $i->quantity])->values()->all(),
            'total' => (float) $order->total,
            'currency' => 'VND',
            'payment' => $order->is_cod ? 'COD' : 'Chuyển khoản',
            'paid' => $order->payment_status === Order::PAYMENT_PAID,
            'carrier' => $order->shipping_carrier,
            'tracking' => $order->tracking_number,
            'trackingUrl' => null,
            'place' => null,
        ]];
    }

    /**
     * A phone number's last orders: when and where they stand.
     *
     * @return array{orders: list<array{number: string, state: string, status: string, date: string|null}>}
     */
    public function byPhone(?string $phone): array
    {
        $hash = PhoneNumber::hash(PhoneNumber::normalize($phone));
        /** @var Collection<int, Order> $orders */
        $orders = $hash ? Order::query()->where('buyer_phone_hash', $hash)->latest('placed_at')->limit(5)->get() : collect();

        return ['orders' => $orders->map(fn (Order $o) => [
            'number' => (string) $o->external_order_id,
            'state' => (string) $o->status,
            'status' => OrderExporter::STATUS[$o->status] ?? (string) $o->status,
            'date' => $o->placed_at?->toIso8601String(),
        ])->values()->all()];
    }
}
