<?php

namespace App\Modules\Marketplace\Connectors\Fake;

use App\Modules\Marketplace\Services\WebhookReceiver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The remote side of the FAKE marketplace: holds its products and orders and sends signed webhooks to our own
 * webhook pipeline exactly as a marketplace would. Development, demo and test only.
 */
class FakeMarketplace
{
    private const BUYERS = [
        ['Nguyễn Văn An', '0901234567', '12 Lê Lợi, P. Bến Nghé, Q.1, TP.HCM'],
        ['Trần Thị Bình', '0912345678', '45 Hàng Bài, Q. Hoàn Kiếm, Hà Nội'],
        ['Lê Hoàng Cường', '0987654321', '88 Nguyễn Văn Linh, Q. Hải Châu, Đà Nẵng'],
        ['Phạm Thu Dung', '0934567890', '7 Trần Hưng Đạo, TP. Cần Thơ'],
        ['Võ Minh Em', '0978123456', '210 Lạch Tray, Q. Ngô Quyền, Hải Phòng'],
    ];

    /**
     * @param  array<int, array{product_id: string, sku_id: string, seller_sku: ?string, title: string, variant_name: ?string, price: string|float|int, stock?: int}>  $skus
     */
    public function putProducts(string $shopId, array $skus): void
    {
        $now = now();
        DB::table('fake_marketplace_products')->upsert(array_map(fn ($s) => [
            'shop_id' => $shopId,
            'product_id' => $s['product_id'],
            'sku_id' => $s['sku_id'],
            'seller_sku' => $s['seller_sku'],
            'title' => $s['title'],
            'variant_name' => $s['variant_name'],
            'price' => $s['price'],
            'stock' => $s['stock'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $skus), ['shop_id', 'sku_id'], ['seller_sku', 'title', 'variant_name', 'price', 'updated_at']);
    }

    /**
     * Creates an order on the fake marketplace and notifies us by webhook.
     *
     * @param  array<int, array{sku_id: string, quantity: int}>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed> the order as stored remotely
     */
    public function createOrder(string $shopId, array $lines, string $status = 'fake_ready', array $overrides = [], bool $notify = true): array
    {
        if (! isset(FakeConnector::STATUSES[$status])) {
            throw new InvalidArgumentException("Unknown fake status {$status}");
        }

        $skus = DB::table('fake_marketplace_products')->where('shop_id', $shopId)
            ->whereIn('sku_id', array_column($lines, 'sku_id'))->get()->keyBy('sku_id');

        $items = [];
        $subtotal = 0;
        foreach (array_values($lines) as $i => $line) {
            $sku = $skus[$line['sku_id']] ?? throw new InvalidArgumentException("SKU {$line['sku_id']} không có trên shop giả lập.");
            $items[] = [
                'item_id' => (string) ($i + 1),
                'product_id' => $sku->product_id,
                'sku_id' => $sku->sku_id,
                'seller_sku' => $sku->seller_sku,
                'name' => $sku->title,
                'variant_name' => $sku->variant_name,
                'quantity' => (int) $line['quantity'],
                'price' => (string) $sku->price,
            ];
            $subtotal += (float) $sku->price * (int) $line['quantity'];
        }

        $buyer = self::BUYERS[crc32($shopId.count($items).microtime()) % count(self::BUYERS)];
        $now = CarbonImmutable::now();
        $shipping = 25000;
        $orderSn = $overrides['order_sn'] ?? 'FK'.strtoupper(Str::random(10));
        $cod = $overrides['cod'] ?? (bool) random_int(0, 1);

        $data = array_merge([
            'order_sn' => $orderSn,
            'created_at' => $now->toIso8601String(),
            'paid_at' => $status === 'fake_unpaid' || $cod ? null : $now->toIso8601String(),
            'ship_by' => $now->addDays(2)->toIso8601String(),
            'payment_method' => $cod ? 'COD' : 'Ví điện tử',
            'cod' => $cod,
            'subtotal' => $subtotal,
            'shipping_fee' => $shipping,
            'discount' => 0,
            'total' => $subtotal + $shipping,
            'buyer' => ['name' => $buyer[0], 'phone' => $buyer[1], 'address' => $buyer[2]],
            'carrier' => 'Giao Hàng Giả Lập',
            'tracking_number' => null,
            'note' => null,
            'items' => $items,
        ], $overrides);

        DB::table('fake_marketplace_orders')->insert([
            'shop_id' => $shopId,
            'order_sn' => $orderSn,
            'status' => $status,
            'data' => json_encode($data),
            'update_time' => $now->format('Y-m-d H:i:s.u'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($notify) {
            $this->notify($orderSn);
        }

        return $this->find($shopId, $orderSn);
    }

    /**
     * Changes the remote status (and optionally data) and sends a webhook.
     *
     * @param  array<string, mixed>  $data
     */
    public function setStatus(string $orderSn, string $status, array $data = [], bool $notify = true): void
    {
        if (! isset(FakeConnector::STATUSES[$status])) {
            throw new InvalidArgumentException("Unknown fake status {$status}");
        }

        DB::transaction(function () use ($orderSn, $status, $data) {
            $row = DB::table('fake_marketplace_orders')->where('order_sn', $orderSn)->lockForUpdate()->first()
                ?? throw new InvalidArgumentException("Order {$orderSn} not found");

            $current = json_decode($row->data, true);
            if ($status === 'fake_packed' && empty($current['tracking_number'])) {
                $data['tracking_number'] ??= 'GHGL'.random_int(10000000, 99999999);
            }

            // update_time strictly increases per order, like a marketplace's update timestamp.
            $previous = CarbonImmutable::parse($row->update_time);
            $now = CarbonImmutable::now();
            $updateTime = $now->greaterThan($previous) ? $now : $previous->addMicrosecond();

            DB::table('fake_marketplace_orders')->where('id', $row->id)->update([
                'status' => $status,
                'data' => json_encode(array_merge($current, $data)),
                'update_time' => $updateTime->format('Y-m-d H:i:s.u'),
                'updated_at' => now(),
            ]);
        });

        if ($notify) {
            $this->notify($orderSn);
        }
    }

    /** @return array<string, mixed>|null */
    public function find(string $shopId, string $orderSn): ?array
    {
        $row = DB::table('fake_marketplace_orders')->where('shop_id', $shopId)->where('order_sn', $orderSn)->first();

        return $row ? ['order_sn' => $row->order_sn, 'status' => $row->status, 'update_time' => $row->update_time, ...json_decode($row->data, true)] : null;
    }

    /**
     * Builds and sends the signed webhook for an order through the same receiver the HTTP endpoint uses.
     *
     * @return array{body: string, headers: array<string, string>, event_id: int|null, duplicate: bool}
     */
    public function notify(string $orderSn, ?string $eventId = null): array
    {
        $row = DB::table('fake_marketplace_orders')->where('order_sn', $orderSn)->first()
            ?? throw new InvalidArgumentException("Order {$orderSn} not found");

        [$body, $headers] = $this->webhook($row->shop_id, $orderSn, $eventId ?? 'evt_'.Str::ulid());
        $result = app(WebhookReceiver::class)->receive(FakeConnector::MARKETPLACE, $body, $headers);

        return ['body' => $body, 'headers' => $headers, 'event_id' => $result['event_id'], 'duplicate' => $result['duplicate']];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    public function webhook(string $shopId, string $orderSn, string $eventId, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $body = json_encode(['type' => 'order_status', 'event_id' => $eventId, 'shop_id' => $shopId, 'order_sn' => $orderSn, 'timestamp' => $timestamp]);

        return [$body, ['X-Fake-Signature' => FakeConnector::sign($body, $timestamp), 'X-Fake-Timestamp' => (string) $timestamp]];
    }
}
