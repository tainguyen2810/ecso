<?php

namespace App\Modules\Marketplace\Connectors\Fake;

use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\Data\OrderData;
use App\Modules\Marketplace\Connectors\Data\OrderItemData;
use App\Modules\Marketplace\Connectors\Data\OrderPage;
use App\Modules\Marketplace\Connectors\Data\ProductData;
use App\Modules\Marketplace\Connectors\Data\ProductPage;
use App\Modules\Marketplace\Connectors\Data\StockUpdateResult;
use App\Modules\Marketplace\Connectors\Data\WebhookMessage;
use App\Modules\Marketplace\Connectors\MarketplaceConnector;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Connector for the FAKE marketplace (tables fake_marketplace_*). It exercises the whole pipeline — webhook
 * signature, inbox, order fetch, inventory push — without calling any real marketplace. It is not, and must
 * never be presented as, an integration with Shopee, TikTok Shop, Lazada or Tiki.
 */
class FakeConnector implements MarketplaceConnector
{
    public const MARKETPLACE = 'fake';

    /** Raw statuses of the fake marketplace (deliberately not any real marketplace's names). */
    public const STATUSES = [
        'fake_unpaid' => 'UNPAID',
        'fake_pending' => 'PENDING_CONFIRMATION',
        'fake_ready' => 'READY_TO_PACK',
        'fake_packed' => 'PACKED',
        'fake_shipped' => 'HANDED_OVER',
        'fake_in_transit' => 'IN_TRANSIT',
        'fake_delivered' => 'DELIVERED',
        'fake_completed' => 'COMPLETED',
        'fake_cancelled' => 'CANCELLED',
        'fake_failed' => 'FAILED_DELIVERY',
        'fake_return_requested' => 'RETURN_REQUESTED',
    ];

    /** action => [allowed from statuses, new status] */
    private const ACTIONS = [
        'confirm' => [['fake_pending'], 'fake_ready'],
        'pack' => [['fake_ready'], 'fake_packed'],
        'handover' => [['fake_packed'], 'fake_shipped'],
        'cancel' => [['fake_unpaid', 'fake_pending', 'fake_ready', 'fake_packed'], 'fake_cancelled'],
    ];

    public function __construct(private readonly FakeMarketplace $remote) {}

    public function marketplace(): string
    {
        return self::MARKETPLACE;
    }

    public function capabilities(): array
    {
        return [
            ...array_fill_keys(Capability::ALL, Capability::SUPPORTED),
            // Left unsupported on purpose so the UnsupportedCapability path is exercised end to end.
            Capability::SHIPPING_LABEL => Capability::UNSUPPORTED,
            Capability::RETURNS_LIST => Capability::UNSUPPORTED,
        ];
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        $headers = array_change_key_case($headers);
        $signature = (string) ($this->header($headers, 'x-fake-signature') ?? '');
        $timestamp = (int) ($this->header($headers, 'x-fake-timestamp') ?? 0);

        if ($signature === '' || abs(time() - $timestamp) > config('marketplaces.webhook_tolerance_seconds')) {
            return false;
        }

        return hash_equals(self::sign($body, $timestamp), $signature);
    }

    public static function sign(string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, (string) config('marketplaces.fake.webhook_secret'));
    }

    public function parseWebhook(string $body): WebhookMessage
    {
        $data = json_decode($body, true);
        if (! is_array($data) || ! isset($data['type'])) {
            throw new ConnectorException('Payload webhook không hợp lệ.', temporary: false);
        }

        return new WebhookMessage(
            shopId: isset($data['shop_id']) ? (string) $data['shop_id'] : null,
            type: (string) $data['type'],
            eventId: isset($data['event_id']) ? (string) $data['event_id'] : null,
            orderId: isset($data['order_sn']) ? (string) $data['order_sn'] : null,
            timestamp: isset($data['timestamp']) ? (int) $data['timestamp'] : null,
        );
    }

    public function getOrder(MarketplaceAccount $account, string $externalOrderId): OrderData
    {
        $this->failIfRequested($account);
        $row = DB::table('fake_marketplace_orders')->where('shop_id', $account->external_shop_id)->where('order_sn', $externalOrderId)->first();
        if (! $row) {
            throw new ConnectorException("Không tìm thấy đơn {$externalOrderId} trên sàn giả lập.", temporary: false);
        }

        $data = json_decode($row->data, true);
        $date = fn (?string $v) => $v ? CarbonImmutable::parse($v) : null;

        return new OrderData(
            externalOrderId: $row->order_sn,
            rawStatus: $row->status,
            updatedAt: CarbonImmutable::parse($row->update_time),
            items: array_map(fn (array $i) => new OrderItemData(
                externalItemId: (string) $i['item_id'],
                externalProductId: $i['product_id'] ?? null,
                externalSkuId: $i['sku_id'] ?? null,
                sellerSku: $i['seller_sku'] ?? null,
                name: $i['name'],
                variantName: $i['variant_name'] ?? null,
                quantity: (int) $i['quantity'],
                unitPrice: (string) $i['price'],
                discount: (string) ($i['discount'] ?? 0),
            ), $data['items']),
            placedAt: $date($data['created_at'] ?? null),
            paidAt: $date($data['paid_at'] ?? null),
            shipByAt: $date($data['ship_by'] ?? null),
            paymentMethod: $data['payment_method'] ?? null,
            isCod: (bool) ($data['cod'] ?? false),
            subtotal: (string) $data['subtotal'],
            shippingFee: (string) $data['shipping_fee'],
            discount: (string) $data['discount'],
            total: (string) $data['total'],
            buyerName: $data['buyer']['name'] ?? null,
            buyerPhone: $data['buyer']['phone'] ?? null,
            shippingAddress: $data['buyer']['address'] ?? null,
            carrier: $data['carrier'] ?? null,
            trackingNumber: $data['tracking_number'] ?? null,
            buyerNote: $data['note'] ?? null,
            cancelReason: $data['cancel_reason'] ?? null,
            raw: $data,
        );
    }

    public function listOrders(MarketplaceAccount $account, CarbonImmutable $from, CarbonImmutable $to, ?string $cursor = null): OrderPage
    {
        $this->failIfRequested($account);
        $page = 100;
        $offset = (int) ($cursor ?? 0);
        $ids = DB::table('fake_marketplace_orders')->where('shop_id', $account->external_shop_id)
            ->whereBetween('update_time', [$from->format('Y-m-d H:i:s.u'), $to->format('Y-m-d H:i:s.u')])->orderBy('id')->offset($offset)->limit($page + 1)->pluck('order_sn')->all();

        return new OrderPage(array_slice($ids, 0, $page), count($ids) > $page ? (string) ($offset + $page) : null);
    }

    public function listProducts(MarketplaceAccount $account, ?string $cursor = null): ProductPage
    {
        $this->failIfRequested($account);
        $rows = DB::table('fake_marketplace_products')->where('shop_id', $account->external_shop_id)->orderBy('id')->get()->groupBy('product_id');

        return new ProductPage($rows->map(fn ($skus, $productId) => new ProductData(
            externalProductId: (string) $productId,
            title: $skus->first()->title,
            variants: $skus->map(fn ($s) => [
                'external_sku_id' => $s->sku_id,
                'seller_sku' => $s->seller_sku,
                'name' => $s->variant_name,
                'price' => (string) $s->price,
                'stock' => (int) $s->stock,
            ])->values()->all(),
            status: 'active',
        ))->values()->all());
    }

    public function updateInventory(MarketplaceAccount $account, array $updates): array
    {
        $this->failIfRequested($account);
        $results = [];
        foreach ($updates as $update) {
            $affected = DB::table('fake_marketplace_products')
                ->where('shop_id', $account->external_shop_id)->where('sku_id', $update->externalSkuId)
                ->update(['stock' => $update->quantity, 'stock_updated_at' => now(), 'updated_at' => now()]);

            $results[] = $affected > 0
                ? new StockUpdateResult($update->externalSkuId, StockUpdateResult::OK)
                : new StockUpdateResult($update->externalSkuId, StockUpdateResult::REJECTED, 'SKU không tồn tại trên sàn giả lập.');
        }

        return $results;
    }

    public function performOrderAction(MarketplaceAccount $account, string $externalOrderId, string $action, array $params = []): void
    {
        $capability = Capability::ORDER_ACTIONS[$action] ?? null;
        if (! $capability || ! isset(self::ACTIONS[$action])) {
            throw new UnsupportedCapability(self::MARKETPLACE, $capability ?? $action);
        }
        $this->failIfRequested($account);

        [$from, $to] = self::ACTIONS[$action];
        $order = $this->remote->find($account->external_shop_id, $externalOrderId);
        if (! $order || ! in_array($order['status'], $from, true)) {
            throw new ConnectorException('Sàn giả lập không cho phép thao tác này ở trạng thái hiện tại.', temporary: false);
        }

        // No webhook here: the caller re-reads the order at once (and attributes the change to the user).
        $this->remote->setStatus($externalOrderId, $to, $action === 'cancel' ? ['cancel_reason' => $params['reason'] ?? 'Người bán hủy'] : [], notify: false);
    }

    /** Tests and the simulator can make a shop's API fail temporarily. */
    private function failIfRequested(MarketplaceAccount $account): void
    {
        if (Cache::get("fake-marketplace:fail:{$account->external_shop_id}")) {
            throw new ConnectorException('Sàn giả lập đang lỗi tạm thời (mô phỏng).');
        }
    }

    private function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }
}
