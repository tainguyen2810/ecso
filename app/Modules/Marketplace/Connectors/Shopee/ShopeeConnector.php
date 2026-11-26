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

    /** The "slot" offered when Shopee asks for no pickup time (or offers none): ship_order goes without one. */
    public const ANY_SLOT = 'shopee';

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

    // ---- Chuẩn bị hàng, shipping label ----------------------------------------------------------------------

    public function shipOptions(MarketplaceAccount $account, string $externalOrderId): array
    {
        $res = (array) ($this->gateway->call($account, 'GET', '/api/v2/logistics/get_shipping_parameter', ['order_sn' => $externalOrderId])['response'] ?? []);
        $needed = (array) ($res['info_needed'] ?? []);
        $addresses = [];
        foreach ((array) ($res['pickup']['address_list'] ?? []) as $a) {
            $flags = (array) ($a['address_flag'] ?? []);
            $slots = [];
            foreach ((array) ($a['time_slot_list'] ?? []) as $t) {
                if (filled($t['pickup_time_id'] ?? null)) {
                    $slots[] = ['id' => (string) $t['pickup_time_id'], 'label' => self::slotLabel((int) ($t['date'] ?? 0), null, filled($t['time_text'] ?? null) ? (string) $t['time_text'] : null)];
                }
            }
            $addresses[] = [
                'id' => (string) $a['address_id'],
                'label' => implode(', ', array_filter([$a['address'] ?? null, $a['district'] ?? null, $a['city'] ?? null, $a['state'] ?? null], 'filled')),
                'slots' => $slots,
                'default' => in_array('pickup_address', $flags, true) || in_array('default_address', $flags, true),
            ];
        }
        // Shopee asks for a pickup time only for some carriers (info_needed.pickup lists pickup_time_id). When it asks
        // for none, or offers no time for any address, Shopee books the pickup itself: one slot that sends no time.
        $timeNeeded = in_array('pickup_time_id', (array) ($needed['pickup'] ?? []), true);
        if (! $timeNeeded || ! in_array(true, array_map(fn ($a) => $a['slots'] !== [], $addresses), true)) {
            foreach ($addresses as &$a) {
                $a['slots'] = $timeNeeded && $a['slots'] !== [] ? $a['slots'] : [['id' => self::ANY_SLOT, 'label' => 'Shopee tự hẹn giờ lấy hàng']];
            }
            unset($a);
        }
        // The shop's pickup address first.
        usort($addresses, fn ($a, $b) => $b['default'] <=> $a['default']);

        return [
            'pickup' => ['available' => array_key_exists('pickup', $needed) && $addresses !== [], 'addresses' => array_map(fn ($a) => ['id' => $a['id'], 'label' => $a['label'], 'slots' => $a['slots']], $addresses)],
            'dropoff' => ['available' => array_key_exists('dropoff', $needed)],
            'carrier' => null,
        ];
    }

    protected function arrange(MarketplaceAccount $account, string $externalOrderId, string $method, ?string $addressId, ?string $slotId): void
    {
        $body = ['order_sn' => $externalOrderId];
        if ($method === 'pickup') {
            if ($addressId === null) {
                throw new ConnectorException('Chọn địa chỉ shipper đến lấy hàng.', temporary: false);
            }
            $body['pickup'] = ['address_id' => (int) $addressId] + ($slotId === null || $slotId === self::ANY_SLOT ? [] : ['pickup_time_id' => $slotId]);
        } else {
            // A drop-off point is only asked for in some regions; the first branch Shopee offers is used then.
            $res = (array) ($this->gateway->call($account, 'GET', '/api/v2/logistics/get_shipping_parameter', ['order_sn' => $externalOrderId])['response'] ?? []);
            $branch = $res['dropoff']['branch_list'][0]['branch_id'] ?? null;
            $body['dropoff'] = in_array('branch_id', (array) ($res['info_needed']['dropoff'] ?? []), true) && $branch !== null ? ['branch_id' => (int) $branch] : (object) [];
        }
        $this->gateway->call($account, 'POST', '/api/v2/logistics/ship_order', [], $body);
    }

    /**
     * Shopee makes the label in steps: pick the document type, ask for it, wait until it is ready, download the PDF
     * (EzChat passes the file on as base64).
     */
    public function shippingLabel(MarketplaceAccount $account, string $externalOrderId): string
    {
        $one = ['order_list' => [['order_sn' => $externalOrderId]]];
        $param = $this->gateway->call($account, 'POST', '/api/v2/logistics/get_shipping_document_parameter', [], $one);
        $type = (string) ($param['response']['result_list'][0]['suggest_shipping_document_type'] ?? 'THERMAL_AIR_WAYBILL');
        $tracking = (string) ($this->gateway->call($account, 'GET', '/api/v2/logistics/get_tracking_number', ['order_sn' => $externalOrderId])['response']['tracking_number'] ?? '');
        if ($tracking === '') {
            throw new ConnectorException('Shopee chưa cấp mã vận đơn cho đơn này. Thử lại sau ít phút.', temporary: true);
        }
        $create = $this->gateway->call($account, 'POST', '/api/v2/logistics/create_shipping_document', [], ['order_list' => [['order_sn' => $externalOrderId, 'tracking_number' => $tracking, 'shipping_document_type' => $type]]]);
        $error = (string) ($create['response']['result_list'][0]['fail_message'] ?? $create['response']['result_list'][0]['fail_error'] ?? '');
        // Asked before (printed already): Shopee keeps the document, go on to download it.
        if ($error !== '' && ! preg_match('/already|exist|created/i', $error)) {
            throw new ConnectorException("Shopee: {$error}", temporary: false);
        }
        for ($try = 0; ; $try++) {
            $result = $this->gateway->call($account, 'POST', '/api/v2/logistics/get_shipping_document_result', [], ['order_list' => [['order_sn' => $externalOrderId, 'shipping_document_type' => $type]]]);
            $status = (string) ($result['response']['result_list'][0]['status'] ?? '');
            if ($status === 'READY') {
                break;
            }
            if ($status === 'FAILED' || $try >= 4) {
                throw new ConnectorException('Shopee chưa tạo xong phiếu giao cho đơn này. Thử in lại sau ít phút.', temporary: true);
            }
            usleep(800_000);
        }
        $file = $this->gateway->call($account, 'POST', '/api/v2/logistics/download_shipping_document', [], ['shipping_document_type' => $type, 'order_list' => [['order_sn' => $externalOrderId]]]);
        $pdf = base64_decode((string) ($file['file'] ?? ''), true);
        if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF')) {
            throw new ConnectorException('Shopee không trả về file phiếu giao. Hãy cập nhật EzChat lên bản mới rồi thử lại.', temporary: false);
        }

        return $pdf;
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
                details: ['description' => $item['description'] ?? null, 'weight_gram' => $weight, 'images' => self::urls($item['image']['image_url_list'] ?? [])],
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
            // Shopee gives pictures to the options of the first tier (the colour, usually).
            $first = (int) (($model['tier_index'] ?? [])[0] ?? -1);
            $image = $tiers[0]['option_list'][$first]['image']['image_url'] ?? null;
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
                'image_url' => is_string($image) && $image !== '' ? $image : null,
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

    /**
     * @param  mixed  $list  picture links as Shopee lists them
     * @return array<int, string> at most 9, cover first
     */
    private static function urls(mixed $list): array
    {
        return array_slice(array_values(array_filter((array) $list, fn ($u) => is_string($u) && $u !== '')), 0, 9);
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

    // ---- Giá ----------------------------------------------------------------------------------------------------

    /** Sets the original (list) price per model; Shopee keeps running discounts on top of it. */
    public function updatePrices(MarketplaceAccount $account, array $updates): array
    {
        $byItem = [];
        foreach ($updates as $u) {
            $byItem[$u->externalProductId][] = $u;
        }

        $results = [];
        foreach ($byItem as $itemId => $rows) {
            $priceList = array_map(fn ($u) => ['model_id' => self::modelId($u->externalSkuId), 'original_price' => (float) self::money($u->price)], $rows);
            try {
                $res = (array) ($this->gateway->call($account, 'POST', '/api/v2/product/update_price', [], ['item_id' => (int) $itemId, 'price_list' => $priceList])['response'] ?? []);
            } catch (ConnectorException $e) {
                if ($e->authExpired) {
                    throw $e;
                }
                foreach ($rows as $u) {
                    $results[] = new StockUpdateResult($u->externalSkuId, $e->temporary ? StockUpdateResult::RETRY : StockUpdateResult::REJECTED, 'Shopee: '.preg_replace('#^/\S+: #', '', $e->getMessage()));
                }

                continue;
            }
            $failed = [];
            foreach ((array) ($res['failure_list'] ?? []) as $f) {
                $failed[(int) ($f['model_id'] ?? 0)] = 'Shopee: '.(string) ($f['failed_reason'] ?? 'từ chối đổi giá');
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

    // ---- Tiền thực nhận -----------------------------------------------------------------------------------------

    /** Shopee's escrow detail: an estimate until the order is completed and the money released (final = null). */
    public function settlement(MarketplaceAccount $account, string $externalOrderId): ?array
    {
        $res = (array) ($this->gateway->call($account, 'GET', '/api/v2/payment/get_escrow_detail', ['order_sn' => $externalOrderId])['response'] ?? []);
        $in = (array) ($res['order_income'] ?? []);
        if (! array_key_exists('escrow_amount', $in)) {
            return null;
        }
        $n = fn (string $k): float => round((float) ($in[$k] ?? 0), 2);
        $fee = fn (string $k): float => abs($n($k));
        $shipping = $n('final_shipping_fee') < 0 ? abs($n('final_shipping_fee')) : 0.0;
        $goods = array_key_exists('cost_of_goods_sold', $in) ? $n('cost_of_goods_sold') : $n('original_price') - $fee('seller_discount');
        $lines = array_values(array_filter([
            ['label' => 'Tiền hàng', 'amount' => $goods],
            ['label' => 'Voucher của shop', 'amount' => -$fee('voucher_from_seller')],
            ['label' => 'Phí cố định (hoa hồng)', 'amount' => -$fee('commission_fee')],
            ['label' => 'Phí dịch vụ', 'amount' => -$fee('service_fee')],
            ['label' => 'Phí thanh toán', 'amount' => -$fee('seller_transaction_fee')],
            ['label' => 'Phí tiếp thị liên kết', 'amount' => -$fee('order_ams_commission_fee')],
            ['label' => 'Phí vận chuyển shop trả', 'amount' => -$shipping],
            ['label' => 'Phí vận chuyển trả hàng', 'amount' => -$fee('reverse_shipping_fee')],
            ['label' => 'Hoàn tiền cho người mua', 'amount' => -$fee('seller_return_refund')],
            ['label' => 'Thuế', 'amount' => -$fee('escrow_tax')],
        ], fn ($l) => abs($l['amount']) >= 0.01));

        return [
            'order_amount' => $goods,
            'commission_fee' => $fee('commission_fee'),
            'payment_fee' => $fee('seller_transaction_fee'),
            'service_fee' => $fee('service_fee') + $fee('order_ams_commission_fee'),
            'fixed_fee' => 0.0,
            'shipping_fee' => $shipping + $fee('reverse_shipping_fee'),
            'net_amount' => $n('escrow_amount'),
            'final' => null,
            'settled_at' => null,
            'lines' => $lines,
        ];
    }

    // ---- Hàng hoàn ----------------------------------------------------------------------------------------------

    /** @var array<string, string> Shopee return status => normalised */
    private const RETURN_STATUS = [
        'REQUESTED' => 'requested', 'JUDGING' => 'requested', 'PROCESSING' => 'requested', 'SELLER_DISPUTE' => 'requested',
        'ACCEPTED' => 'approved', 'CLOSED' => 'refunded', 'CANCELLED' => 'cancelled',
    ];

    /** Returns updated since $since (Shopee keeps 15-day windows); cursor = "window start|page". */
    public function listReturns(MarketplaceAccount $account, CarbonImmutable $since, ?string $cursor = null): array
    {
        [$from, $page] = $cursor !== null ? array_map('intval', explode('|', $cursor)) + [0, 0] : [$since->getTimestamp(), 0];
        $to = min($from + 15 * 86400, now()->getTimestamp());
        $res = (array) ($this->gateway->call($account, 'GET', '/api/v2/returns/get_return_list', [
            'page_no' => $page, 'page_size' => 50, 'update_time_from' => $from, 'update_time_to' => $to,
        ])['response'] ?? []);
        $returns = [];
        foreach ((array) ($res['return'] ?? []) as $r) {
            $raw = strtoupper((string) ($r['status'] ?? ''));
            $tracking = filled($r['tracking_number'] ?? null) ? (string) $r['tracking_number'] : null;
            $status = self::RETURN_STATUS[$raw] ?? 'requested';
            if ($status === 'approved' && $tracking !== null) {
                $status = 'shipping';
            }
            $returns[] = [
                'id' => (string) $r['return_sn'],
                'order_id' => (string) $r['order_sn'],
                'status' => $status,
                'raw_status' => $raw,
                'goods' => (bool) ($r['needs_logistics'] ?? true),
                'reason' => trim(implode(': ', array_filter([self::returnReason((string) ($r['reason'] ?? '')), $r['text_reason'] ?? null], 'filled'))) ?: null,
                'refund' => is_numeric($r['refund_amount'] ?? null) ? (float) $r['refund_amount'] : null,
                'tracking_number' => $tracking,
                'respond_by' => $status === 'requested' ? self::time($r['due_date'] ?? null) : null,
                'items' => array_map(fn ($i) => [
                    'external_sku_id' => self::skuId((int) ($i['item_id'] ?? 0), (int) ($i['model_id'] ?? 0)),
                    'seller_sku' => filled($i['variation_sku'] ?? null) ? (string) $i['variation_sku'] : (filled($i['item_sku'] ?? null) ? (string) $i['item_sku'] : null),
                    'name' => (string) ($i['name'] ?? ''),
                    'quantity' => max(1, (int) ($i['amount'] ?? 1)),
                ], (array) ($r['item'] ?? [])),
                'images' => array_slice(array_values(array_filter((array) ($r['image'] ?? []), fn ($u) => is_string($u) && str_starts_with($u, 'https://'))), 0, 6),
            ];
        }
        $next = ($res['more'] ?? false) ? "{$from}|".($page + 1) : ($to < now()->getTimestamp() ? "{$to}|0" : null);

        return ['returns' => $returns, 'next' => $next];
    }

    private static function returnReason(string $code): ?string
    {
        return [
            'NOT_RECEIPT' => 'Chưa nhận được hàng', 'WRONG_ITEM' => 'Giao sai hàng', 'ITEM_DAMAGED' => 'Hàng bị hư hỏng', 'DIFFERENT_DESCRIPTION' => 'Khác mô tả',
            'MUTUAL_AGREE' => 'Hai bên đồng ý', 'OTHER' => null, 'ITEM_MISSING' => 'Thiếu hàng', 'ITEM_WRONGDAMAGED' => 'Sai hàng hoặc hỏng',
            'CHANGE_MIND' => 'Đổi ý', 'EXPECTATION_FAILED' => 'Không như mong đợi', 'DAMAGED_PACKAGE' => 'Bao bì hư hỏng', 'FUNCTIONAL_DMG' => 'Hàng không hoạt động',
        ][$code] ?? ($code !== '' ? $code : null);
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
