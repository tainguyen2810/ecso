<?php

namespace App\Modules\Marketplace\Connectors\WooCommerce;

use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\Data\OrderData;
use App\Modules\Marketplace\Connectors\Data\OrderItemData;
use App\Modules\Marketplace\Connectors\Data\OrderPage;
use App\Modules\Marketplace\Connectors\Data\ProductData;
use App\Modules\Marketplace\Connectors\Data\ProductPage;
use App\Modules\Marketplace\Connectors\Data\ShopAuthorization;
use App\Modules\Marketplace\Connectors\Data\StockUpdate;
use App\Modules\Marketplace\Connectors\Data\StockUpdateResult;
use App\Modules\Marketplace\Connectors\Data\WebhookMessage;
use App\Modules\Marketplace\Connectors\FetchesSingleProduct;
use App\Modules\Marketplace\Connectors\MarketplaceConnector;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use App\Modules\Orders\Domain\OrderStatus;
use Carbon\CarbonImmutable;

/**
 * WooCommerce store (REST API v3, written against woocommerce.github.io/woocommerce-rest-api-docs; tested with the
 * simulated store in tests/Support/FakeWooStore.php, not yet against a live store).
 *
 * A store is connected with REST API keys (granted by the owner through Woo's /wc-auth/v1/authorize screen, or typed
 * in), not through a marketplace app. Woo only has coarse order statuses; ECSO's packing stages are stored on the
 * Woo order as the meta "_ecso_stage" and read back as raw status "processing:packed" / "processing:handed_over",
 * so the order re-read from Woo after an action is always the truth.
 */
class WooCommerceConnector implements FetchesSingleProduct, MarketplaceConnector
{
    public const MARKETPLACE = 'woocommerce';

    public const STAGE_META = '_ecso_stage';

    /** raw status => internal status (Woo statuses + ECSO stages). */
    public const STATUSES = [
        'pending' => OrderStatus::UNPAID,
        'on-hold' => OrderStatus::PENDING_CONFIRMATION,
        'processing' => OrderStatus::READY_TO_PACK,
        'processing:packed' => OrderStatus::PACKED,
        'processing:handed_over' => OrderStatus::HANDED_OVER,
        'completed' => OrderStatus::COMPLETED,
        'cancelled' => OrderStatus::CANCELLED,
        'failed' => OrderStatus::CANCELLED,
        'refunded' => OrderStatus::RETURNED,
        'trash' => OrderStatus::CANCELLED,
    ];

    /** Topics registered on the store when it is connected. */
    public const WEBHOOK_TOPICS = ['order.created', 'order.updated', 'product.created', 'product.updated', 'product.deleted'];

    /** Order statuses polled by the reconcile job (checkout drafts are carts, not orders). */
    private const ORDER_STATUSES = 'pending,processing,on-hold,completed,cancelled,refunded,failed';

    private const PAGE = 100;

    public function marketplace(): string
    {
        return self::MARKETPLACE;
    }

    public function capabilities(): array
    {
        $supported = [
            Capability::ORDERS_WEBHOOK, Capability::ORDERS_FETCH, Capability::ORDERS_LIST, Capability::ORDERS_CONFIRM,
            Capability::ORDERS_PACK, Capability::ORDERS_HANDOVER, Capability::ORDERS_CANCEL, Capability::PRODUCTS_LIST,
            Capability::INVENTORY_UPDATE, Capability::INVENTORY_GET,
        ];

        return [
            ...array_fill_keys(Capability::ALL, Capability::UNSUPPORTED),
            ...array_fill_keys($supported, Capability::SUPPORTED),
        ];
    }

    // ---- Webhooks -------------------------------------------------------------------------------------------

    /**
     * Woo signs the raw body with the store's webhook secret: base64(HMAC-SHA256(body, secret)) in
     * X-WC-Webhook-Signature. The store is found by X-WC-Webhook-Source; its own secret decides validity, so a
     * forged source header only selects a secret the sender does not know.
     */
    public function verifyWebhook(string $body, array $headers): bool
    {
        $headers = array_change_key_case($headers);
        $signature = (string) ($this->header($headers, 'x-wc-webhook-signature') ?? '');
        $source = (string) ($this->header($headers, 'x-wc-webhook-source') ?? '');
        if ($signature === '' || $source === '') {
            return false;
        }

        $account = $this->accountForSource($source);
        $secret = $account ? MarketplaceCredential::query()->withoutGlobalScopes()->where('marketplace_account_id', $account->id)->first()?->webhook_secret : null;
        if (blank($secret)) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $body, (string) $secret, true)), $signature);
    }

    /** Woo pings a new webhook with the form body "webhook_id=N" (unsigned). It is answered 200 and not stored. */
    public static function isPing(string $body): bool
    {
        return (bool) preg_match('/^webhook_id=\d+$/', trim($body));
    }

    public function parseWebhook(string $body, array $headers = []): WebhookMessage
    {
        $headers = array_change_key_case($headers);
        $topic = (string) ($this->header($headers, 'x-wc-webhook-topic') ?? '');
        $data = json_decode($body, true);
        if ($topic === '' || ! is_array($data) || ! isset($data['id'])) {
            throw new ConnectorException('Payload webhook WooCommerce không hợp lệ.', temporary: false);
        }

        $id = (string) $data['id'];
        $isOrder = str_starts_with($topic, 'order.');
        // Woo sends a new delivery id on every retry; the event is the resource version, so retries are duplicates.
        $version = (string) ($data['date_modified_gmt'] ?? $this->header($headers, 'x-wc-webhook-delivery-id') ?? '');

        return new WebhookMessage(
            shopId: WooClient::storeId((string) $this->header($headers, 'x-wc-webhook-source')),
            type: $topic,
            eventId: hash('sha256', "{$topic}|{$id}|{$version}"),
            orderId: $isOrder ? $id : null,
            productId: $isOrder ? null : (string) ($data['parent_id'] ?? 0 ?: $id),
        );
    }

    // ---- Orders -----------------------------------------------------------------------------------------------

    public function getOrder(MarketplaceAccount $account, string $externalOrderId): OrderData
    {
        $order = WooClient::forAccount($account)->get('orders/'.rawurlencode($externalOrderId));

        return $this->toOrderData($order);
    }

    public function listOrders(MarketplaceAccount $account, CarbonImmutable $from, CarbonImmutable $to, ?string $cursor = null): OrderPage
    {
        $page = max(1, (int) ($cursor ?? 1));
        $response = WooClient::forAccount($account)->send('GET', 'orders', [
            'modified_after' => $from->utc()->format('Y-m-d\TH:i:s'),
            'modified_before' => $to->utc()->format('Y-m-d\TH:i:s'),
            'dates_are_gmt' => 'true',
            'status' => self::ORDER_STATUSES,
            'per_page' => self::PAGE,
            'page' => $page,
            'orderby' => 'id',
            'order' => 'asc',
            '_fields' => 'id',
        ]);
        $ids = array_map(fn ($o) => (string) $o['id'], (array) $response->json());
        $pages = (int) $response->header('X-WP-TotalPages');

        return new OrderPage($ids, $page < $pages ? (string) ($page + 1) : null);
    }

    public function performOrderAction(MarketplaceAccount $account, string $externalOrderId, string $action, array $params = []): void
    {
        $body = match ($action) {
            'confirm' => ['status' => 'processing'],
            'pack' => ['meta_data' => [['key' => self::STAGE_META, 'value' => 'packed']]],
            'handover' => ['meta_data' => [['key' => self::STAGE_META, 'value' => 'handed_over']]],
            'cancel' => ['status' => 'cancelled'],
            default => throw new UnsupportedCapability(self::MARKETPLACE, Capability::ORDER_ACTIONS[$action] ?? $action),
        };

        $client = WooClient::forAccount($account);
        $client->put('orders/'.rawurlencode($externalOrderId), $body);

        if ($action === 'cancel' && filled($params['reason'] ?? null)) {
            try {
                $client->post('orders/'.rawurlencode($externalOrderId).'/notes', ['note' => 'ECSO: hủy đơn - '.$params['reason']]);
            } catch (ConnectorException) {
                // The cancellation itself succeeded; the note is only informative.
            }
        }
    }

    /** @param  array<string, mixed>  $o */
    public function toOrderData(array $o): OrderData
    {
        $date = fn (?string $v) => filled($v) ? CarbonImmutable::parse($v, 'UTC') : null;
        $stage = collect($o['meta_data'] ?? [])->firstWhere('key', self::STAGE_META)['value'] ?? null;
        $raw = (string) ($o['status'] ?? '');
        if ($raw === 'processing' && in_array($stage, ['packed', 'handed_over'], true)) {
            $raw .= ':'.$stage;
        }

        $items = [];
        $subtotal = 0.0;
        foreach ($o['line_items'] ?? [] as $line) {
            $qty = max(1, (int) $line['quantity']);
            $lineSubtotal = (float) ($line['subtotal'] ?? 0);
            $lineTotal = (float) ($line['total'] ?? $lineSubtotal);
            $subtotal += $lineSubtotal;
            $variation = (int) ($line['variation_id'] ?? 0);
            $product = (int) ($line['product_id'] ?? 0);
            $items[] = new OrderItemData(
                externalItemId: (string) $line['id'],
                externalProductId: $product ? (string) $product : null,
                externalSkuId: ($variation ?: $product) ? (string) ($variation ?: $product) : null,
                sellerSku: filled($line['sku'] ?? null) ? (string) $line['sku'] : null,
                name: (string) ($line['parent_name'] ?? null ?: $line['name']),
                variantName: $this->lineVariantName($line),
                quantity: $qty,
                unitPrice: number_format($lineSubtotal / $qty, 2, '.', ''),
                discount: number_format(max(0, $lineSubtotal - $lineTotal), 2, '.', ''),
            );
        }

        $billing = (array) ($o['billing'] ?? []);
        $shipping = (array) ($o['shipping'] ?? []);
        $address = filled($shipping['address_1'] ?? null) ? $shipping : $billing;
        $name = trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')) ?: trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? ''));
        $phone = ($shipping['phone'] ?? null) ?: ($billing['phone'] ?? null);
        $addressLine = implode(', ', array_filter([$address['address_1'] ?? null, $address['address_2'] ?? null, $address['city'] ?? null, $address['state'] ?? null]));
        $carrier = collect($o['shipping_lines'] ?? [])->pluck('method_title')->filter()->implode(', ');

        return new OrderData(
            externalOrderId: (string) $o['id'],
            rawStatus: $raw,
            updatedAt: $date($o['date_modified_gmt'] ?? null) ?? CarbonImmutable::now(),
            items: $items,
            placedAt: $date($o['date_created_gmt'] ?? null),
            paidAt: $date($o['date_paid_gmt'] ?? null),
            paymentMethod: filled($o['payment_method_title'] ?? null) ? (string) $o['payment_method_title'] : ($o['payment_method'] ?? null),
            isCod: ($o['payment_method'] ?? null) === 'cod',
            currency: (string) ($o['currency'] ?? 'VND'),
            subtotal: number_format($subtotal, 2, '.', ''),
            shippingFee: number_format((float) ($o['shipping_total'] ?? 0), 2, '.', ''),
            discount: number_format((float) ($o['discount_total'] ?? 0), 2, '.', ''),
            total: number_format((float) ($o['total'] ?? 0), 2, '.', ''),
            buyerName: $name !== '' ? $name : null,
            buyerPhone: filled($phone) ? (string) $phone : null,
            shippingAddress: $addressLine !== '' ? $addressLine : null,
            carrier: $carrier !== '' ? $carrier : null,
            buyerNote: filled($o['customer_note'] ?? null) ? (string) $o['customer_note'] : null,
            raw: array_intersect_key($o, array_flip(['id', 'number', 'status', 'payment_method', 'customer_id', 'date_modified_gmt'])),
        );
    }

    /** @param  array<string, mixed>  $line */
    private function lineVariantName(array $line): ?string
    {
        if (empty($line['variation_id'])) {
            return null;
        }
        $values = collect($line['meta_data'] ?? [])
            ->filter(fn ($m) => is_scalar($m['display_value'] ?? null) && ! str_starts_with((string) ($m['key'] ?? ''), '_'))
            ->map(fn ($m) => strip_tags((string) $m['display_value']))->implode(' / ');

        return $values !== '' ? $values : null;
    }

    // ---- Products and stock -------------------------------------------------------------------------------------

    public function listProducts(MarketplaceAccount $account, ?string $cursor = null): ProductPage
    {
        $client = WooClient::forAccount($account);
        $page = max(1, (int) ($cursor ?? 1));
        $response = $client->send('GET', 'products', ['per_page' => self::PAGE, 'page' => $page, 'status' => 'any', 'orderby' => 'id', 'order' => 'asc']);
        $products = array_values(array_filter(array_map(fn ($p) => $this->toProductData($client, $p), (array) $response->json())));
        $pages = (int) $response->header('X-WP-TotalPages');

        return new ProductPage($products, $page < $pages ? (string) ($page + 1) : null);
    }

    public function getProduct(MarketplaceAccount $account, string $externalProductId): ?ProductData
    {
        $client = WooClient::forAccount($account);
        try {
            $product = $client->get('products/'.rawurlencode($externalProductId));
        } catch (ConnectorException $e) {
            if (! $e->temporary && ! $e->authExpired && str_starts_with($e->getMessage(), 'Không tìm thấy')) {
                return null;
            }
            throw $e;
        }

        return ($product['status'] ?? null) === 'trash' ? null : $this->toProductData($client, $product);
    }

    /**
     * Variants carry the Woo prices for the price sync: "price" is what a buyer pays now (the sale price while a
     * sale is running, else the regular price), as Woo computes it.
     *
     * @param  array<string, mixed>  $p
     */
    private function toProductData(WooClient $client, array $p): ?ProductData
    {
        if (in_array($p['type'] ?? 'simple', ['grouped', 'external'], true)) {
            return null; // not stocked items
        }

        $variants = [];
        if (($p['type'] ?? null) === 'variable') {
            $page = 1;
            do {
                $response = $client->send('GET', "products/{$p['id']}/variations", ['per_page' => self::PAGE, 'page' => $page]);
                foreach ((array) $response->json() as $v) {
                    $variants[] = $this->variant($v, collect($v['attributes'] ?? [])->pluck('option')->filter()->implode(' / ') ?: null);
                }
                $pages = (int) $response->header('X-WP-TotalPages');
                $page++;
            } while ($page <= $pages);
        } else {
            $variants[] = $this->variant($p, null);
        }

        return new ProductData(
            externalProductId: (string) $p['id'],
            title: html_entity_decode(strip_tags((string) ($p['name'] ?? '')), ENT_QUOTES),
            variants: $variants,
            status: (string) ($p['status'] ?? 'publish'),
            imageUrl: $p['images'][0]['src'] ?? ($p['image']['src'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{external_sku_id: string, seller_sku: ?string, name: ?string, price: string, stock: ?int, regular_price: ?string, sale_price: ?string}
     */
    private function variant(array $v, ?string $name): array
    {
        $money = fn ($x) => is_numeric($x) ? number_format((float) $x, 2, '.', '') : null;

        return [
            'external_sku_id' => (string) $v['id'],
            'seller_sku' => filled($v['sku'] ?? null) ? (string) $v['sku'] : null,
            'name' => $name,
            'price' => $money($v['price'] ?? null) ?? '0.00',
            'stock' => ($v['manage_stock'] ?? false) === true && isset($v['stock_quantity']) ? (int) $v['stock_quantity'] : null,
            'regular_price' => $money($v['regular_price'] ?? null),
            'sale_price' => $money($v['sale_price'] ?? null),
        ];
    }

    /**
     * Pushes "available to sell" as the Woo stock (manage_stock on), through the batch endpoints: products for
     * simple products, products/{id}/variations for variations (100 per call).
     */
    public function updateInventory(MarketplaceAccount $account, array $updates): array
    {
        $client = WooClient::forAccount($account);
        $results = [];
        $groups = collect($updates)->groupBy(fn (StockUpdate $u) => $u->externalProductId === $u->externalSkuId || $u->externalProductId === '' ? 'products' : "products/{$u->externalProductId}/variations");

        foreach ($groups as $path => $group) {
            foreach ($group->chunk(100) as $chunk) {
                $response = $client->post("{$path}/batch", ['update' => $chunk->map(fn (StockUpdate $u) => [
                    'id' => (int) $u->externalSkuId, 'manage_stock' => true, 'stock_quantity' => max(0, $u->quantity),
                ])->values()->all()]);
                $returned = collect($response['update'] ?? []);
                foreach ($chunk as $update) {
                    $row = $returned->first(fn ($r) => (string) ($r['id'] ?? '') === $update->externalSkuId && ! isset($r['error']))
                        ?? $returned->first(fn ($r) => isset($r['error']) && (string) ($r['id'] ?? '') === $update->externalSkuId);
                    $results[] = match (true) {
                        $row === null => new StockUpdateResult($update->externalSkuId, StockUpdateResult::RETRY, 'Website không trả kết quả cho sản phẩm này'),
                        isset($row['error']) => new StockUpdateResult($update->externalSkuId, StockUpdateResult::REJECTED, strip_tags((string) ($row['error']['message'] ?? 'Website từ chối'))),
                        default => new StockUpdateResult($update->externalSkuId, StockUpdateResult::OK),
                    };
                }
            }
        }

        return $results;
    }

    // ---- Not used for Woo -------------------------------------------------------------------------------------

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        throw new UnsupportedCapability(self::MARKETPLACE, Capability::SHOP_AUTHORIZE);
    }

    public function completeAuthorization(array $query, string $redirectUri): ShopAuthorization
    {
        throw new UnsupportedCapability(self::MARKETPLACE, Capability::SHOP_AUTHORIZE);
    }

    public function refreshCredentials(MarketplaceAccount $account): ShopAuthorization
    {
        throw new UnsupportedCapability(self::MARKETPLACE, Capability::TOKEN_REFRESH);
    }

    private function accountForSource(string $source): ?MarketplaceAccount
    {
        return MarketplaceAccount::query()->withoutGlobalScopes()->where('marketplace', self::MARKETPLACE)
            ->where('external_shop_id', WooClient::storeId($source))->where('status', MarketplaceAccount::CONNECTED)->first();
    }

    /** @param  array<string, mixed>  $headers */
    private function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }
}
