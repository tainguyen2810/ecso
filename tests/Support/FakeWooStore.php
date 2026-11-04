<?php

namespace Tests\Support;

use App\Modules\Marketplace\Connectors\WooCommerce\WooClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * In-memory WooCommerce store behind Http::fake(), answering the REST API v3 calls the connector makes, with the
 * shapes documented at woocommerce.github.io/woocommerce-rest-api-docs. Test support only.
 */
class FakeWooStore
{
    /** @var array<int, array<string, mixed>> */
    public array $products = [];

    /** @var array<int, array<int, array<string, mixed>>> parent id => variations */
    public array $variations = [];

    /** @var array<int, array<string, mixed>> */
    public array $orders = [];

    /** @var array<int, array<string, mixed>> */
    public array $webhooks = [];

    /** @var array<int, array{method: string, path: string, body: mixed}> */
    public array $calls = [];

    public bool $readOnly = false;

    public bool $stripAuthorization = false;

    private int $nextId = 900;

    public function __construct(public string $url = 'https://shop.example.vn', public string $key = 'ck_test', public string $secret = 'cs_test')
    {
        Http::fake(fn (Request $request) => $this->handle($request));
        // Every made-up store host resolves to a public address, whatever the runner's DNS says about it.
        WooClient::$resolver = fn (string $host) => ['93.184.216.34'];
    }

    public function addSimple(int $id, string $sku, string $name, string $regular, ?string $sale = null, int $stock = 5): void
    {
        $this->products[$id] = ['id' => $id, 'name' => $name, 'type' => 'simple', 'status' => 'publish', 'sku' => $sku,
            'price' => $sale ?? $regular, 'regular_price' => $regular, 'sale_price' => $sale ?? '', 'manage_stock' => true,
            'stock_quantity' => $stock, 'images' => [['src' => "https://cdn.example.vn/{$id}.jpg"]]];
    }

    /** @param  array<int, array{0: int, 1: string, 2: string, 3: string}>  $variations  [id, sku, option, price] */
    public function addVariable(int $id, string $name, array $variations): void
    {
        $this->products[$id] = ['id' => $id, 'name' => $name, 'type' => 'variable', 'status' => 'publish', 'sku' => '', 'price' => '', 'images' => []];
        foreach ($variations as [$vid, $sku, $option, $price]) {
            $this->variations[$id][$vid] = ['id' => $vid, 'parent_id' => $id, 'sku' => $sku, 'price' => $price, 'regular_price' => $price,
                'sale_price' => '', 'manage_stock' => true, 'stock_quantity' => 3, 'attributes' => [['name' => 'Màu', 'option' => $option]]];
        }
    }

    /** @param  array<int, array{0: int, 1: int, 2: string, 3: int, 4?: int}>  $lines  [product, qty, sku, unit price, variation] */
    public function addOrder(int $id, string $status, array $lines, string $paymentMethod = 'cod'): array
    {
        $items = [];
        $total = 0;
        foreach ($lines as $i => $l) {
            $sub = $l[1] * $l[3];
            $total += $sub;
            $items[] = ['id' => $id * 10 + $i, 'product_id' => $l[0], 'variation_id' => $l[4] ?? 0, 'sku' => $l[2], 'name' => "SP {$l[2]}",
                'quantity' => $l[1], 'subtotal' => (string) $sub, 'total' => (string) $sub, 'price' => $l[3], 'meta_data' => []];
        }

        return $this->orders[$id] = ['id' => $id, 'number' => (string) $id, 'status' => $status, 'currency' => 'VND',
            'date_created_gmt' => '2026-11-04T01:00:00', 'date_modified_gmt' => now()->utc()->format('Y-m-d\TH:i:s'),
            'date_paid_gmt' => null, 'payment_method' => $paymentMethod, 'payment_method_title' => $paymentMethod === 'cod' ? 'Thanh toán khi nhận hàng' : 'Chuyển khoản',
            'total' => (string) ($total + 30000), 'shipping_total' => '30000', 'discount_total' => '0', 'customer_note' => 'Giao giờ hành chính',
            'billing' => ['first_name' => 'Lan', 'last_name' => 'Nguyễn', 'phone' => '0901234567', 'address_1' => '12 Lê Lợi', 'city' => 'Huế'],
            'shipping' => ['first_name' => '', 'last_name' => '', 'address_1' => ''], 'shipping_lines' => [['method_title' => 'GHTK']],
            'line_items' => $items, 'meta_data' => []];
    }

    /** Signs a webhook body the way Woo does. */
    public function signed(string $topic, array $payload, ?string $secret = null, ?string $source = null): array
    {
        $body = json_encode($payload);

        return [$body, [
            'X-WC-Webhook-Topic' => $topic,
            'X-WC-Webhook-Source' => $source ?? $this->url.'/',
            'X-WC-Webhook-Delivery-ID' => (string) random_int(1, 99999),
            'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $body, $secret ?? ($this->webhooks ? end($this->webhooks)['secret'] : 'x'), true)),
            'Content-Type' => 'application/json',
        ]];
    }

    private function handle(Request $request)
    {
        $parts = parse_url($request->url());
        if (! str_starts_with($request->url(), $this->url.'/wp-json/wc/v3/')) {
            return Http::response(['code' => 'rest_no_route', 'message' => 'No route'], 404);
        }
        parse_str($parts['query'] ?? '', $query);
        $path = substr($parts['path'], strlen((string) parse_url($this->url, PHP_URL_PATH).'/wp-json/wc/v3/'));
        $method = $request->method();
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $request->data()];

        $basic = $request->hasHeader('Authorization') && ! $this->stripAuthorization
            && $request->header('Authorization')[0] === 'Basic '.base64_encode("{$this->key}:{$this->secret}");
        $viaQuery = ($query['consumer_key'] ?? null) === $this->key && ($query['consumer_secret'] ?? null) === $this->secret;
        if (! $basic && ! $viaQuery) {
            return Http::response(['code' => 'woocommerce_rest_cannot_view', 'message' => 'Sorry, you cannot list resources.'], 401);
        }
        if ($this->readOnly && $method !== 'GET') {
            return Http::response(['code' => 'woocommerce_rest_cannot_create', 'message' => 'Sorry, you are not allowed to create resources.'], 401);
        }

        return match (true) {
            $path === 'system_status' => Http::response(['environment' => ['home_url' => $this->url, 'version' => '9.3.3'], 'settings' => ['store_name' => 'Cửa hàng Woo']]),
            $path === 'products' && $method === 'GET' => $this->page(array_values($this->products), $query),
            $path === 'products/batch' => $this->batch($this->products, $request->data()['update'] ?? []),
            (bool) preg_match('#^products/(\d+)/variations$#', $path, $m) => $this->page(array_values($this->variations[(int) $m[1]] ?? []), $query),
            (bool) preg_match('#^products/(\d+)/variations/batch$#', $path, $m) => $this->batchVariations((int) $m[1], $request->data()['update'] ?? []),
            (bool) preg_match('#^products/(\d+)$#', $path, $m) => isset($this->products[(int) $m[1]])
                ? Http::response($this->products[(int) $m[1]]) : Http::response(['code' => 'woocommerce_rest_product_invalid_id', 'message' => 'Invalid ID.'], 404),
            $path === 'orders' => $this->page(array_values(array_filter($this->orders, fn ($o) => in_array($o['status'], explode(',', $query['status'] ?? $o['status']), true))), $query),
            (bool) preg_match('#^orders/(\d+)/notes$#', $path, $m) => Http::response(['id' => $this->nextId++, 'note' => $request->data()['note']], 201),
            (bool) preg_match('#^orders/(\d+)$#', $path, $m) && $method === 'PUT' => $this->updateOrder((int) $m[1], $request->data()),
            (bool) preg_match('#^orders/(\d+)$#', $path, $m) => Http::response($this->orders[(int) $m[1]] ?? ['code' => 'woocommerce_rest_shop_order_invalid_id', 'message' => 'Invalid ID.'], isset($this->orders[(int) $m[1]]) ? 200 : 404),
            $path === 'webhooks' && $method === 'POST' => $this->createWebhook($request->data()),
            (bool) preg_match('#^webhooks/(\d+)$#', $path, $m) && $method === 'DELETE' => $this->deleteWebhook((int) $m[1]),
            default => Http::response(['code' => 'rest_no_route', 'message' => 'No route'], 404),
        };
    }

    private function page(array $rows, array $query)
    {
        $per = (int) ($query['per_page'] ?? 10);
        $page = (int) ($query['page'] ?? 1);

        return Http::response(array_slice($rows, ($page - 1) * $per, $per), 200, [
            'X-WP-Total' => (string) count($rows), 'X-WP-TotalPages' => (string) max(1, (int) ceil(count($rows) / max(1, $per))),
        ]);
    }

    private function batch(array &$rows, array $updates)
    {
        $out = [];
        foreach ($updates as $u) {
            if (! isset($rows[$u['id']])) {
                $out[] = ['id' => $u['id'], 'error' => ['code' => 'woocommerce_rest_product_invalid_id', 'message' => 'Invalid ID.']];

                continue;
            }
            $rows[$u['id']] = array_merge($rows[$u['id']], $u);
            if (isset($u['regular_price']) || isset($u['sale_price'])) {
                // Like WooCommerce: the selling price is the sale price when there is one.
                $row = $rows[$u['id']];
                $rows[$u['id']]['price'] = ($row['sale_price'] ?? '') !== '' ? $row['sale_price'] : $row['regular_price'];
            }
            $out[] = $rows[$u['id']];
        }

        return Http::response(['update' => $out]);
    }

    private function batchVariations(int $parent, array $updates)
    {
        $rows = $this->variations[$parent] ?? [];
        $response = $this->batch($rows, $updates);
        $this->variations[$parent] = $rows;

        return $response;
    }

    private function updateOrder(int $id, array $data)
    {
        if (isset($data['status'])) {
            $this->orders[$id]['status'] = $data['status'];
        }
        foreach ($data['meta_data'] ?? [] as $meta) {
            $this->orders[$id]['meta_data'] = array_values(array_filter($this->orders[$id]['meta_data'], fn ($m) => $m['key'] !== $meta['key']));
            $this->orders[$id]['meta_data'][] = $meta;
        }
        $this->orders[$id]['date_modified_gmt'] = now()->addSecond()->utc()->format('Y-m-d\TH:i:s');

        return Http::response($this->orders[$id]);
    }

    private function createWebhook(array $data)
    {
        $id = $this->nextId++;
        $this->webhooks[$id] = $data + ['id' => $id];

        return Http::response($this->webhooks[$id], 201);
    }

    private function deleteWebhook(int $id)
    {
        unset($this->webhooks[$id]);

        return Http::response(['id' => $id]);
    }
}
