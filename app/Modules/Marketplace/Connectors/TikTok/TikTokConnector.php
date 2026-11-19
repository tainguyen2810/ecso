<?php

namespace App\Modules\Marketplace\Connectors\TikTok;

use App\Modules\Marketplace\Connectors\Data\OrderData;
use App\Modules\Marketplace\Connectors\Data\OrderItemData;
use App\Modules\Marketplace\Connectors\Data\OrderPage;
use App\Modules\Marketplace\Connectors\Data\ProductData;
use App\Modules\Marketplace\Connectors\Data\ProductPage;
use App\Modules\Marketplace\Connectors\Data\StockUpdateResult;
use App\Modules\Marketplace\Connectors\Data\WebhookMessage;
use App\Modules\Marketplace\Connectors\EzChat\GatewayConnector;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Domain\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Shop Partner API (version 202309) through EzChat (EzChat holds the app key/secret, the shop token and
 * the shop cipher; see EzChatGateway). Answers here are TikTok's "data" object.
 */
class TikTokConnector extends GatewayConnector
{
    public const MARKETPLACE = 'tiktok';

    /** TikTok order status -> ECSO status (seeded into marketplace_status_maps). */
    public const STATUSES = [
        'UNPAID' => OrderStatus::UNPAID,
        'ON_HOLD' => OrderStatus::PENDING_CONFIRMATION,
        'AWAITING_SHIPMENT' => OrderStatus::READY_TO_PACK,
        'PARTIALLY_SHIPPING' => OrderStatus::PACKED,
        'AWAITING_COLLECTION' => OrderStatus::PACKED,
        'IN_TRANSIT' => OrderStatus::IN_TRANSIT,
        'DELIVERED' => OrderStatus::DELIVERED,
        'COMPLETED' => OrderStatus::COMPLETED,
        'CANCELLED' => OrderStatus::CANCELLED,
    ];

    private const V = '202309';

    public function marketplace(): string
    {
        return self::MARKETPLACE;
    }

    protected function ezchatType(): string
    {
        return 'TIKTOK_SHOP';
    }

    // ---- Webhooks ---------------------------------------------------------------------------------------------

    /** TikTok push: {type, tts_notification_id, shop_id, timestamp, data}; data.order_id or data.product_id. */
    public function parseWebhook(string $body, array $headers = []): WebhookMessage
    {
        $push = json_decode($body, true);
        if (! is_array($push) || ! isset($push['shop_id'])) {
            throw new ConnectorException('Payload push TikTok Shop không hợp lệ.', temporary: false);
        }
        $data = (array) ($push['data'] ?? []);
        $order = filled($data['order_id'] ?? null) ? (string) $data['order_id'] : null;
        $product = $order === null && filled($data['product_id'] ?? null) ? (string) $data['product_id'] : null;

        return new WebhookMessage(
            shopId: (string) $push['shop_id'],
            type: 'tiktok.'.($push['type'] ?? 'unknown'),
            eventId: filled($push['tts_notification_id'] ?? null) ? (string) $push['tts_notification_id'] : hash('sha256', $body),
            orderId: $order,
            timestamp: isset($push['timestamp']) ? (int) $push['timestamp'] : null,
            productId: $product,
        );
    }

    // ---- Orders -----------------------------------------------------------------------------------------------

    public function getOrder(MarketplaceAccount $account, string $externalOrderId): OrderData
    {
        $data = $this->gateway->call($account, 'GET', '/order/'.self::V.'/orders', ['ids' => $externalOrderId]);
        $order = $data['orders'][0] ?? null;
        if (! is_array($order)) {
            throw new ConnectorException("Không tìm thấy đơn {$externalOrderId} trên TikTok Shop.", temporary: false, notFound: true);
        }

        return $this->toOrderData($order);
    }

    public function listOrders(MarketplaceAccount $account, CarbonImmutable $from, CarbonImmutable $to, ?string $cursor = null): OrderPage
    {
        $data = $this->gateway->call($account, 'POST', '/order/'.self::V.'/orders/search', array_filter([
            'page_size' => 50,
            'sort_field' => 'update_time',
            'sort_order' => 'ASC',
            'page_token' => $cursor ?? '',
        ], fn ($v) => $v !== ''), [
            'update_time_ge' => $from->getTimestamp(),
            'update_time_lt' => $to->getTimestamp(),
        ]);
        $ids = array_values(array_map(fn ($o) => (string) $o['id'], array_filter((array) ($data['orders'] ?? []), fn ($o) => isset($o['id']))));

        return new OrderPage($ids, filled($data['next_page_token'] ?? null) ? (string) $data['next_page_token'] : null);
    }

    /** @param  array<string, mixed>  $o */
    public function toOrderData(array $o): OrderData
    {
        // TikTok lists one line per unit; units of the same SKU are merged into one item.
        $lines = [];
        foreach ((array) ($o['line_items'] ?? []) as $line) {
            $key = (string) ($line['sku_id'] ?? $line['id'] ?? count($lines));
            $sale = (float) ($line['sale_price'] ?? 0);
            $original = (float) ($line['original_price'] ?? $sale);
            if (! isset($lines[$key])) {
                $lines[$key] = ['line' => $line, 'qty' => 0, 'price' => $sale, 'discount' => 0.0];
            }
            $lines[$key]['qty']++;
            $lines[$key]['discount'] += max(0, $original - $sale);
        }
        $items = [];
        foreach ($lines as $key => $l) {
            $line = $l['line'];
            $items[] = new OrderItemData(
                externalItemId: (string) $key,
                externalProductId: filled($line['product_id'] ?? null) ? (string) $line['product_id'] : null,
                externalSkuId: filled($line['sku_id'] ?? null) ? (string) $line['sku_id'] : null,
                sellerSku: filled($line['seller_sku'] ?? null) ? (string) $line['seller_sku'] : null,
                name: (string) ($line['product_name'] ?? ''),
                variantName: filled($line['sku_name'] ?? null) ? (string) $line['sku_name'] : null,
                quantity: $l['qty'],
                unitPrice: self::money($l['price']),
                discount: self::money($l['discount']),
            );
        }
        $pay = (array) ($o['payment'] ?? []);
        $address = (array) ($o['recipient_address'] ?? []);
        $discount = (float) ($pay['seller_discount'] ?? 0) + (float) ($pay['platform_discount'] ?? 0);
        $status = (string) ($o['status'] ?? '');
        $tracking = $o['tracking_number'] ?? ($o['packages'][0]['tracking_number'] ?? null);

        return new OrderData(
            externalOrderId: (string) $o['id'],
            rawStatus: $status,
            updatedAt: self::time($o['update_time'] ?? null) ?? CarbonImmutable::now(),
            items: $items,
            placedAt: self::time($o['create_time'] ?? null),
            paidAt: self::time($o['paid_time'] ?? null),
            shipByAt: self::time($o['rts_sla_time'] ?? $o['tts_sla_time'] ?? null),
            paymentMethod: filled($o['payment_method_name'] ?? null) ? (string) $o['payment_method_name'] : null,
            isCod: (bool) ($o['is_cod'] ?? false),
            currency: (string) ($pay['currency'] ?? 'VND'),
            subtotal: self::money($pay['sub_total'] ?? 0),
            shippingFee: self::money($pay['shipping_fee'] ?? 0),
            discount: self::money($discount),
            total: self::money($pay['total_amount'] ?? 0),
            buyerName: filled($address['name'] ?? null) ? (string) $address['name'] : null,
            buyerPhone: filled($address['phone_number'] ?? null) ? (string) $address['phone_number'] : null,
            shippingAddress: filled($address['full_address'] ?? null) ? (string) $address['full_address'] : null,
            carrier: filled($o['shipping_provider'] ?? null) ? (string) $o['shipping_provider'] : null,
            trackingNumber: filled($tracking) ? (string) $tracking : null,
            buyerNote: filled($o['buyer_message'] ?? null) ? (string) $o['buyer_message'] : null,
            cancelReason: filled($o['cancel_reason'] ?? null) ? (string) $o['cancel_reason'] : null,
            raw: ['status' => $status],
            buyerAccountId: filled($o['user_id'] ?? null) ? (string) $o['user_id'] : null,
        );
    }

    // ---- Chuẩn bị hàng, shipping label ----------------------------------------------------------------------

    public function shipOptions(MarketplaceAccount $account, string $externalOrderId): array
    {
        $package = $this->packageId($account, $externalOrderId);
        $d = $this->gateway->call($account, 'GET', '/fulfillment/'.self::V.'/packages/'.rawurlencode($package).'/handover_time_slots');
        $slots = [];
        foreach ((array) ($d['pickup_slots'] ?? []) as $slot) {
            // TikTok spells it "avaliable" in this answer.
            if (($slot['avaliable'] ?? $slot['available'] ?? true) === false || ! isset($slot['start_time'])) {
                continue;
            }
            $slots[] = ['id' => $slot['start_time'].'-'.($slot['end_time'] ?? $slot['start_time']), 'label' => self::slotLabel((int) $slot['start_time'], isset($slot['end_time']) ? (int) $slot['end_time'] : null)];
        }

        return [
            // TikTok picks up at the shop's own pickup address (set in Seller Center): no address to choose.
            'pickup' => ['available' => ($d['can_pickup'] ?? true) !== false && $slots !== [], 'addresses' => [['id' => '', 'label' => 'Địa chỉ lấy hàng của shop trên TikTok', 'slots' => $slots]]],
            'dropoff' => ['available' => ($d['can_drop_off'] ?? true) !== false],
            'carrier' => null,
        ];
    }

    protected function arrange(MarketplaceAccount $account, string $externalOrderId, string $method, ?string $addressId, ?string $slotId): void
    {
        $package = $this->packageId($account, $externalOrderId);
        $body = ['handover_method' => $method === 'pickup' ? 'PICKUP' : 'DROP_OFF'];
        if ($method === 'pickup') {
            [$start, $end] = array_pad(explode('-', (string) $slotId, 2), 2, null);
            $body['pickup_slot'] = ['start_time' => (int) $start, 'end_time' => (int) ($end ?? $start)];
        }
        $this->gateway->call($account, 'POST', '/fulfillment/'.self::V.'/packages/'.rawurlencode($package).'/ship', [], $body);
    }

    /** The label is a link to a PDF on TikTok's file server. */
    public function shippingLabel(MarketplaceAccount $account, string $externalOrderId): string
    {
        $package = $this->packageId($account, $externalOrderId);
        $d = $this->gateway->call($account, 'GET', '/fulfillment/'.self::V.'/packages/'.rawurlencode($package).'/shipping_documents', [
            'document_type' => 'SHIPPING_LABEL', 'document_size' => 'A6',
        ]);
        $url = (string) ($d['doc_url'] ?? '');
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            throw new ConnectorException('TikTok Shop chưa có phiếu giao cho đơn này. Thử lại sau ít phút.', temporary: true);
        }
        $response = Http::timeout(30)->get($url);
        if (! $response->successful() || ! str_starts_with($response->body(), '%PDF')) {
            throw new ConnectorException('Không tải được phiếu giao từ TikTok Shop. Thử lại sau ít phút.', temporary: true);
        }

        return $response->body();
    }

    /** "Chuẩn bị hàng" and labels work on the order's package. */
    private function packageId(MarketplaceAccount $account, string $externalOrderId): string
    {
        $data = $this->gateway->call($account, 'GET', '/order/'.self::V.'/orders', ['ids' => $externalOrderId]);
        $package = $data['orders'][0]['packages'][0]['id'] ?? null;
        if (blank($package)) {
            throw new ConnectorException('TikTok Shop chưa tạo kiện hàng cho đơn này.', temporary: false);
        }

        return (string) $package;
    }

    // ---- Products ---------------------------------------------------------------------------------------------

    public function listProducts(MarketplaceAccount $account, ?string $cursor = null): ProductPage
    {
        $data = $this->gateway->call($account, 'POST', '/product/'.self::V.'/products/search', array_filter([
            'page_size' => 50,
            'page_token' => $cursor ?? '',
        ], fn ($v) => $v !== ''), ['status' => 'ACTIVATE']);
        $products = array_map(fn ($p) => $this->toProductData((array) $p), array_values(array_filter((array) ($data['products'] ?? []), fn ($p) => isset($p['id']))));

        return new ProductPage($products, filled($data['next_page_token'] ?? null) ? (string) $data['next_page_token'] : null);
    }

    public function getProduct(MarketplaceAccount $account, string $externalProductId): ?ProductData
    {
        try {
            $p = $this->product($account, $externalProductId);
        } catch (ConnectorException $e) {
            if ($e->notFound) {
                return null;
            }
            throw $e;
        }
        if (in_array($p['status'] ?? '', ['DELETED', 'PLATFORM_DEACTIVATED'], true)) {
            return null;
        }

        return $this->toProductData($p);
    }

    /** @return array<string, mixed> */
    private function product(MarketplaceAccount $account, string $productId): array
    {
        return $this->gateway->call($account, 'GET', '/product/'.self::V.'/products/'.rawurlencode($productId));
    }

    /** @param  array<string, mixed>  $p */
    private function toProductData(array $p): ProductData
    {
        $variants = [];
        foreach ((array) ($p['skus'] ?? []) as $sku) {
            $options = [];
            $image = null;
            foreach ((array) ($sku['sales_attributes'] ?? []) as $attr) {
                if (filled($attr['value_name'] ?? null)) {
                    $options[(string) ($attr['name'] ?? 'Phân loại')] = (string) $attr['value_name'];
                }
                $image ??= $attr['sku_img']['urls'][0] ?? null;
            }
            $price = (array) ($sku['price'] ?? []);
            $inventory = $sku['inventory'] ?? null;
            $variants[] = [
                'external_sku_id' => (string) $sku['id'],
                'seller_sku' => filled($sku['seller_sku'] ?? null) ? (string) $sku['seller_sku'] : null,
                'name' => $options !== [] ? implode(', ', $options) : null,
                'price' => self::money($price['sale_price'] ?? $price['tax_exclusive_price'] ?? 0),
                'stock' => is_array($inventory) ? array_sum(array_map(fn ($i) => (int) ($i['quantity'] ?? 0), $inventory)) : null,
                'options' => $options,
                'image_url' => is_string($image) && $image !== '' ? $image : null,
            ];
        }
        $weight = (array) ($p['package_weight'] ?? []);
        $grams = is_numeric($weight['value'] ?? null)
            ? (int) round((float) $weight['value'] * (strtoupper((string) ($weight['unit'] ?? 'KILOGRAM')) === 'GRAM' ? 1 : 1000))
            : null;

        return new ProductData(
            externalProductId: (string) $p['id'],
            title: (string) ($p['title'] ?? ''),
            variants: $variants,
            status: isset($p['status']) ? strtolower((string) $p['status']) : null,
            imageUrl: $p['main_images'][0]['urls'][0] ?? null,
            details: [
                'description' => $p['description'] ?? null,
                'weight_gram' => $grams,
                'images' => array_slice(array_values(array_filter(array_map(fn ($i) => $i['urls'][0] ?? null, (array) ($p['main_images'] ?? [])), fn ($u) => is_string($u) && $u !== '')), 0, 9),
            ],
        );
    }

    // ---- Inventory --------------------------------------------------------------------------------------------

    /** Stock goes to the warehouse each SKU already uses (read from the product first). */
    public function updateInventory(MarketplaceAccount $account, array $updates): array
    {
        $byProduct = [];
        foreach ($updates as $u) {
            $byProduct[$u->externalProductId][] = $u;
        }

        $results = [];
        foreach ($byProduct as $productId => $rows) {
            try {
                $warehouses = [];
                foreach ((array) ($this->product($account, (string) $productId)['skus'] ?? []) as $sku) {
                    $warehouses[(string) $sku['id']] = $sku['inventory'][0]['warehouse_id'] ?? null;
                }
                $skus = [];
                foreach ($rows as $u) {
                    if (! array_key_exists($u->externalSkuId, $warehouses)) {
                        $results[] = new StockUpdateResult($u->externalSkuId, StockUpdateResult::REJECTED, 'SKU không còn trên TikTok Shop');

                        continue;
                    }
                    $inventory = ['quantity' => max(0, $u->quantity)];
                    if (filled($warehouses[$u->externalSkuId])) {
                        $inventory['warehouse_id'] = (string) $warehouses[$u->externalSkuId];
                    }
                    $skus[$u->externalSkuId] = ['id' => $u->externalSkuId, 'inventory' => [$inventory]];
                }
                if ($skus === []) {
                    continue;
                }
                $this->gateway->call($account, 'POST', '/product/'.self::V.'/products/'.rawurlencode((string) $productId).'/inventory/update', [], ['skus' => array_values($skus)]);
                foreach (array_keys($skus) as $skuId) {
                    $results[] = new StockUpdateResult((string) $skuId, StockUpdateResult::OK);
                }
            } catch (ConnectorException $e) {
                if ($e->authExpired) {
                    throw $e;
                }
                $done = array_map(fn (StockUpdateResult $r) => $r->externalSkuId, $results);
                foreach ($rows as $u) {
                    if (! in_array($u->externalSkuId, $done, true)) {
                        $results[] = new StockUpdateResult($u->externalSkuId, $e->temporary ? StockUpdateResult::RETRY : StockUpdateResult::REJECTED, $e->getMessage());
                    }
                }
            }
        }

        return $results;
    }

    private static function time(mixed $unix): ?CarbonImmutable
    {
        return is_numeric($unix) && (int) $unix > 0 ? CarbonImmutable::createFromTimestamp((int) $unix) : null;
    }
}
