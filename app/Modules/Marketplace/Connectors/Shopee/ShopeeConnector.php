<?php

namespace App\Modules\Marketplace\Connectors\Shopee;

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

/**
 * Shopee Open Platform v2 through EzChat (EzChat holds the partner app and the shop tokens; see EzChatGateway).
 *
 * SKU ids: a model's model_id; an item without models uses "i{item_id}" (Shopee reports model_id 0 for it).
 */
class ShopeeConnector extends GatewayConnector
{
    public const MARKETPLACE = 'shopee';

    /** Shopee order_status -> ECSO status (seeded into marketplace_status_maps). */
    public const STATUSES = [
        'UNPAID' => OrderStatus::UNPAID,
        'INVOICE_PENDING' => OrderStatus::PENDING_CONFIRMATION,
        'READY_TO_SHIP' => OrderStatus::READY_TO_PACK,
        'PROCESSED' => OrderStatus::PACKED,
        'RETRY_SHIP' => OrderStatus::PACKED,
        'SHIPPED' => OrderStatus::IN_TRANSIT,
        'TO_CONFIRM_RECEIVE' => OrderStatus::DELIVERED,
        'IN_CANCEL' => OrderStatus::READY_TO_PACK,
        'CANCELLED' => OrderStatus::CANCELLED,
        'TO_RETURN' => OrderStatus::RETURN_REQUESTED,
        'COMPLETED' => OrderStatus::COMPLETED,
    ];

    /** get_order_list accepts at most 15 days per call. */
    private const MAX_WINDOW_SECONDS = 15 * 86400;

    private const ORDER_FIELDS = 'buyer_user_id,buyer_username,estimated_shipping_fee,actual_shipping_fee,recipient_address,item_list,pay_time,payment_method,total_amount,shipping_carrier,cancel_reason,cod,note,message_to_seller';

    public function marketplace(): string
    {
        return self::MARKETPLACE;
    }

    protected function ezchatType(): string
    {
        return 'SHOPEE';
    }

    // ---- Webhooks ---------------------------------------------------------------------------------------------

    /** Shopee push: {shop_id, code, timestamp, data}; order pushes carry data.ordersn, product pushes data.item_id. */
    public function parseWebhook(string $body, array $headers = []): WebhookMessage
    {
        $push = json_decode($body, true);
        if (! is_array($push) || ! isset($push['shop_id'])) {
            throw new ConnectorException('Payload push Shopee không hợp lệ.', temporary: false);
        }
        $data = (array) ($push['data'] ?? []);
        $order = isset($data['ordersn']) ? (string) $data['ordersn'] : null;
        $item = $order === null && isset($data['item_id']) ? (string) $data['item_id'] : null;

        return new WebhookMessage(
            shopId: (string) $push['shop_id'],
            type: 'shopee.'.($push['code'] ?? 'unknown'),
            eventId: hash('sha256', $body),
            orderId: $order,
            timestamp: isset($push['timestamp']) ? (int) $push['timestamp'] : null,
            productId: $item,
        );
    }

    // ---- Orders -----------------------------------------------------------------------------------------------

    public function getOrder(MarketplaceAccount $account, string $externalOrderId): OrderData
    {
        $res = $this->gateway->call($account, 'GET', '/api/v2/order/get_order_detail', [
            'order_sn_list' => $externalOrderId,
            'response_optional_fields' => self::ORDER_FIELDS,
        ]);
        $order = $res['response']['order_list'][0] ?? null;
        if (! is_array($order)) {
            throw new ConnectorException("Không tìm thấy đơn {$externalOrderId} trên Shopee.", temporary: false, notFound: true);
        }

        return $this->toOrderData($order, $this->trackingNumber($account, $externalOrderId, (string) ($order['order_status'] ?? '')));
    }

    /** Cursor: "{window start}|{Shopee cursor}" so a range longer than 15 days is read window by window. */
    public function listOrders(MarketplaceAccount $account, CarbonImmutable $from, CarbonImmutable $to, ?string $cursor = null): OrderPage
    {
        [$start, $pageCursor] = $cursor !== null && str_contains($cursor, '|') ? explode('|', $cursor, 2) : [(string) $from->getTimestamp(), ''];
        $start = (int) $start;
        $end = min($to->getTimestamp(), $start + self::MAX_WINDOW_SECONDS);

        $res = $this->gateway->call($account, 'GET', '/api/v2/order/get_order_list', array_filter([
            'time_range_field' => 'update_time',
            'time_from' => $start,
            'time_to' => $end,
            'page_size' => 100,
            'cursor' => $pageCursor,
        ], fn ($v) => $v !== ''));
        $page = (array) ($res['response'] ?? []);
        $ids = array_values(array_map(fn ($o) => (string) $o['order_sn'], array_filter((array) ($page['order_list'] ?? []), fn ($o) => isset($o['order_sn']))));

        $next = match (true) {
            ! empty($page['more']) && filled($page['next_cursor'] ?? null) => "{$start}|{$page['next_cursor']}",
            $end < $to->getTimestamp() => "{$end}|",
            default => null,
        };

        return new OrderPage($ids, $next);
    }

    /** @param  array<string, mixed>  $o */
    public function toOrderData(array $o, ?string $trackingNumber = null): OrderData
    {
        $items = [];
        $subtotal = 0.0;
        foreach ((array) ($o['item_list'] ?? []) as $i => $line) {
            $qty = (int) ($line['model_quantity_purchased'] ?? 0);
            $price = (float) ($line['model_discounted_price'] ?? $line['model_original_price'] ?? 0);
            $original = (float) ($line['model_original_price'] ?? $price);
            $subtotal += $price * $qty;
            $modelId = (int) ($line['model_id'] ?? 0);
            $items[] = new OrderItemData(
                externalItemId: (string) ($line['order_item_id'] ?? $line['item_id'] ?? $i).':'.$modelId,
                externalProductId: isset($line['item_id']) ? (string) $line['item_id'] : null,
                externalSkuId: self::skuId((int) ($line['item_id'] ?? 0), $modelId),
                sellerSku: filled($line['model_sku'] ?? null) ? (string) $line['model_sku'] : (filled($line['item_sku'] ?? null) ? (string) $line['item_sku'] : null),
                name: (string) ($line['item_name'] ?? ''),
                variantName: filled($line['model_name'] ?? null) ? (string) $line['model_name'] : null,
                quantity: $qty,
                unitPrice: self::money($price),
                discount: self::money(max(0, ($original - $price) * $qty)),
            );
        }
        $shipping = (float) ($o['actual_shipping_fee'] ?? 0) ?: (float) ($o['estimated_shipping_fee'] ?? 0);
        $address = (array) ($o['recipient_address'] ?? []);
        $status = (string) ($o['order_status'] ?? '');

        return new OrderData(
            externalOrderId: (string) $o['order_sn'],
            rawStatus: $status,
            updatedAt: self::time($o['update_time'] ?? null) ?? CarbonImmutable::now(),
            items: $items,
            placedAt: self::time($o['create_time'] ?? null),
            paidAt: self::time($o['pay_time'] ?? null),
            shipByAt: self::time($o['ship_by_date'] ?? null),
            paymentMethod: filled($o['payment_method'] ?? null) ? (string) $o['payment_method'] : null,
            isCod: (bool) ($o['cod'] ?? false),
            currency: (string) ($o['currency'] ?? 'VND'),
            subtotal: self::money($subtotal),
            shippingFee: self::money($shipping),
            total: self::money($o['total_amount'] ?? $subtotal),
            buyerName: filled($address['name'] ?? null) ? (string) $address['name'] : null,
            buyerPhone: filled($address['phone'] ?? null) ? (string) $address['phone'] : null,
            shippingAddress: filled($address['full_address'] ?? null) ? (string) $address['full_address'] : null,
            carrier: filled($o['shipping_carrier'] ?? null) ? (string) $o['shipping_carrier'] : null,
            trackingNumber: $trackingNumber,
            buyerNote: filled($o['message_to_seller'] ?? null) ? (string) $o['message_to_seller'] : null,
            cancelReason: filled($o['cancel_reason'] ?? null) ? (string) $o['cancel_reason'] : null,
            raw: ['order_status' => $status],
            buyerAccountId: isset($o['buyer_user_id']) ? (string) $o['buyer_user_id'] : null,
            buyerAccountName: filled($o['buyer_username'] ?? null) ? (string) $o['buyer_username'] : null,
        );
    }

    /** Tracking exists once the order is arranged for shipping; earlier it is simply not there yet. */
    private function trackingNumber(MarketplaceAccount $account, string $orderSn, string $status): ?string
    {
        if (in_array($status, ['UNPAID', 'INVOICE_PENDING', 'READY_TO_SHIP', 'CANCELLED', 'IN_CANCEL'], true)) {
            return null;
        }
        try {
            $res = $this->gateway->call($account, 'GET', '/api/v2/logistics/get_tracking_number', ['order_sn' => $orderSn]);

            return filled($res['response']['tracking_number'] ?? null) ? (string) $res['response']['tracking_number'] : null;
        } catch (ConnectorException $e) {
            if ($e->authExpired) {
                throw $e;
            }

            return null;
        }
    }

    // ---- Products ---------------------------------------------------------------------------------------------

    /** Cursor: the offset of the next page of get_item_list. */
    public function listProducts(MarketplaceAccount $account, ?string $cursor = null): ProductPage
    {
        $res = $this->gateway->call($account, 'GET', '/api/v2/product/get_item_list', [
            'offset' => (int) ($cursor ?? 0),
            'page_size' => 50,
            'item_status' => 'NORMAL',
        ]);
        $page = (array) ($res['response'] ?? []);
        $ids = array_values(array_filter(array_map(fn ($i) => (int) ($i['item_id'] ?? 0), (array) ($page['item'] ?? []))));

        return new ProductPage(
            $this->products($account, $ids),
            ! empty($page['has_next_page']) ? (string) ($page['next_offset'] ?? ((int) ($cursor ?? 0) + 50)) : null,
        );
    }

    public function getProduct(MarketplaceAccount $account, string $externalProductId): ?ProductData
    {
        return $this->products($account, [(int) $externalProductId])[0] ?? null;
    }

    /**
     * @param  array<int, int>  $itemIds  at most 50
     * @return array<int, ProductData>
     */
    private function products(MarketplaceAccount $account, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $res = $this->gateway->call($account, 'GET', '/api/v2/product/get_item_base_info', ['item_id_list' => implode(',', $itemIds)]);
        $products = [];
        foreach ((array) ($res['response']['item_list'] ?? []) as $item) {
            if (in_array($item['item_status'] ?? '', ['DELETED', 'SHOPEE_DELETE'], true)) {
                continue;
            }
            $itemId = (int) $item['item_id'];
            $weight = is_numeric($item['weight'] ?? null) ? (int) round((float) $item['weight'] * 1000) : null;
            $variants = ! empty($item['has_model'])
                ? $this->models($account, $itemId, $weight)
                : [[
                    'external_sku_id' => self::skuId($itemId, 0),
                    'seller_sku' => filled($item['item_sku'] ?? null) ? (string) $item['item_sku'] : null,
                    'name' => null,
                    ...self::priceAndStock($item),
                    'weight_gram' => $weight,
                ]];
            $products[] = new ProductData(
                externalProductId: (string) $itemId,
                title: (string) ($item['item_name'] ?? ''),
                variants: $variants,
                status: isset($item['item_status']) ? strtolower((string) $item['item_status']) : null,
                imageUrl: $item['image']['image_url_list'][0] ?? null,
                details: ['description' => $item['description'] ?? null, 'weight_gram' => $weight],
            );
        }

        return $products;
    }

    /** @return array<int, array<string, mixed>> */
    private function models(MarketplaceAccount $account, int $itemId, ?int $weight): array
    {
        $res = (array) ($this->gateway->call($account, 'GET', '/api/v2/product/get_model_list', ['item_id' => $itemId])['response'] ?? []);
        $tiers = (array) ($res['tier_variation'] ?? []);
        $variants = [];
        foreach ((array) ($res['model'] ?? []) as $model) {
            $options = [];
            foreach ((array) ($model['tier_index'] ?? []) as $t => $index) {
                $tier = (array) ($tiers[$t] ?? []);
                $value = $tier['option_list'][$index]['option'] ?? null;
                if ($value !== null) {
                    $options[(string) ($tier['name'] ?? 'Phân loại '.($t + 1))] = (string) $value;
                }
            }
            $variants[] = [
                'external_sku_id' => self::skuId($itemId, (int) $model['model_id']),
                'seller_sku' => filled($model['model_sku'] ?? null) ? (string) $model['model_sku'] : null,
                'name' => $options !== [] ? implode(', ', $options) : null,
                ...self::priceAndStock($model),
                'options' => $options,
                'weight_gram' => $weight,
            ];
        }

        return $variants;
    }

    /**
     * @param  array<string, mixed>  $row  an item or a model
     * @return array{price: string, stock: ?int, regular_price: ?string, sale_price: ?string}
     */
    private static function priceAndStock(array $row): array
    {
        $price = (array) ($row['price_info'][0] ?? []);
        $current = $price['current_price'] ?? null;
        $original = $price['original_price'] ?? $current;
        $stockInfo = (array) ($row['stock_info_v2'] ?? []);
        $stock = $stockInfo['seller_stock'] ?? null;
        $stock = is_array($stock) ? array_sum(array_map(fn ($s) => (int) ($s['stock'] ?? 0), $stock)) : ($stockInfo['summary_info']['total_available_stock'] ?? null);

        return [
            'price' => self::money($current),
            'stock' => $stock !== null ? (int) $stock : null,
            'regular_price' => $original !== null ? self::money($original) : null,
            'sale_price' => $current !== null && $original !== null && (float) $current < (float) $original ? self::money($current) : null,
        ];
    }

    // ---- Inventory --------------------------------------------------------------------------------------------

    public function updateInventory(MarketplaceAccount $account, array $updates): array
    {
        $byItem = [];
        foreach ($updates as $u) {
            $byItem[$u->externalProductId][] = $u;
        }

        $results = [];
        foreach ($byItem as $itemId => $rows) {
            $stockList = array_map(fn ($u) => ['model_id' => self::modelId($u->externalSkuId), 'seller_stock' => [['stock' => max(0, $u->quantity)]]], $rows);
            try {
                $res = (array) ($this->gateway->call($account, 'POST', '/api/v2/product/update_stock', [], ['item_id' => (int) $itemId, 'stock_list' => $stockList])['response'] ?? []);
            } catch (ConnectorException $e) {
                if ($e->authExpired) {
                    throw $e;
                }
                foreach ($rows as $u) {
                    $results[] = new StockUpdateResult($u->externalSkuId, $e->temporary ? StockUpdateResult::RETRY : StockUpdateResult::REJECTED, $e->getMessage());
                }

                continue;
            }
            $failed = [];
            foreach ((array) ($res['failure_list'] ?? []) as $f) {
                $failed[(int) ($f['model_id'] ?? 0)] = (string) ($f['failed_reason'] ?? 'Shopee từ chối cập nhật tồn kho');
            }
            foreach ($rows as $u) {
                $model = self::modelId($u->externalSkuId);
                $results[] = isset($failed[$model])
                    ? new StockUpdateResult($u->externalSkuId, StockUpdateResult::REJECTED, $failed[$model])
                    : new StockUpdateResult($u->externalSkuId, StockUpdateResult::OK);
            }
        }

        return $results;
    }

    // ---- Helpers ----------------------------------------------------------------------------------------------

    public static function skuId(int $itemId, int $modelId): string
    {
        return $modelId > 0 ? (string) $modelId : "i{$itemId}";
    }

    private static function modelId(string $skuId): int
    {
        return str_starts_with($skuId, 'i') ? 0 : (int) $skuId;
    }

    private static function time(mixed $unix): ?CarbonImmutable
    {
        return is_numeric($unix) && (int) $unix > 0 ? CarbonImmutable::createFromTimestamp((int) $unix) : null;
    }
}
