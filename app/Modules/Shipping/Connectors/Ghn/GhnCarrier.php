<?php

namespace App\Modules\Shipping\Connectors\Ghn;

use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Shipping\Connectors\CarrierConnector;
use App\Modules\Shipping\Connectors\CarrierException;
use App\Modules\Shipping\Connectors\Data\AccountInfo;
use App\Modules\Shipping\Connectors\Data\CarrierEvent;
use App\Modules\Shipping\Connectors\Data\CreatedShipment;
use App\Modules\Shipping\Connectors\Data\ServiceQuote;
use App\Modules\Shipping\Connectors\Data\ShipmentDraft;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Giao Hàng Nhanh with the shop's own token (Trang khách hàng GHN → Chủ cửa hàng → Xem token). Each GHN "cửa hàng"
 * (ShopId) is a pickup point. Built from GHN's public documentation; not yet tried against a real shop account, so
 * any refusal is shown to the shop word for word.
 */
class GhnCarrier implements CarrierConnector
{
    public const KEY = 'ghn';

    /** GHN status => our shipment status. */
    public const STATUS_MAP = [
        'ready_to_pick' => ShipmentStatus::AWAITING_PICKUP,
        'picking' => ShipmentStatus::AWAITING_PICKUP,
        'money_collect_picking' => ShipmentStatus::AWAITING_PICKUP,
        'picked' => ShipmentStatus::PICKED_UP,
        'storing' => ShipmentStatus::IN_TRANSIT,
        'transporting' => ShipmentStatus::IN_TRANSIT,
        'sorting' => ShipmentStatus::IN_TRANSIT,
        'delivering' => ShipmentStatus::OUT_FOR_DELIVERY,
        'money_collect_delivering' => ShipmentStatus::OUT_FOR_DELIVERY,
        'delivered' => ShipmentStatus::DELIVERED,
        'delivery_fail' => ShipmentStatus::FAILED,
        'waiting_to_return' => ShipmentStatus::FAILED,
        'return' => ShipmentStatus::RETURNING,
        'return_transporting' => ShipmentStatus::RETURNING,
        'return_sorting' => ShipmentStatus::RETURNING,
        'returning' => ShipmentStatus::RETURNING,
        'return_fail' => ShipmentStatus::RETURNING,
        'returned' => ShipmentStatus::RETURNED,
        'cancel' => ShipmentStatus::CANCELLED,
        // exception, damage, lost: kept in the history for the shop to follow up, no status change.
    ];

    public const STATUS_LABELS = [
        'ready_to_pick' => 'Chờ lấy hàng', 'picking' => 'Đang lấy hàng', 'money_collect_picking' => 'Đang lấy hàng',
        'picked' => 'Đã lấy hàng', 'storing' => 'Nhập kho', 'transporting' => 'Đang luân chuyển', 'sorting' => 'Đang phân loại',
        'delivering' => 'Đang giao hàng', 'money_collect_delivering' => 'Đang giao hàng', 'delivered' => 'Giao hàng thành công',
        'delivery_fail' => 'Giao hàng thất bại', 'waiting_to_return' => 'Chờ chuyển hoàn', 'return' => 'Chuyển hoàn',
        'return_transporting' => 'Đang luân chuyển hàng hoàn', 'return_sorting' => 'Đang phân loại hàng hoàn', 'returning' => 'Đang hoàn hàng',
        'return_fail' => 'Hoàn hàng thất bại', 'returned' => 'Đã hoàn hàng cho shop', 'exception' => 'Hàng ngoại lệ', 'damage' => 'Hàng hư hỏng',
        'lost' => 'Hàng thất lạc', 'cancel' => 'Hủy đơn',
    ];

    private const REQUIRED_NOTES = [
        ShipmentDraft::NOTE_VIEW_NO_TRY => 'CHOXEMHANGKHONGTHU',
        ShipmentDraft::NOTE_TRY => 'CHOTHUHANG',
        ShipmentDraft::NOTE_NO_VIEW => 'KHONGCHOXEMHANG',
    ];

    public function __construct(private readonly GhnClient $client, private readonly GhnAddressResolver $addresses) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'GHN';
    }

    public function credentialFields(): array
    {
        return ['token' => 'Token API của shop GHN'];
    }

    public function verify(array $credentials, string $environment): AccountInfo
    {
        $token = trim((string) ($credentials['token'] ?? ''));
        if ($token === '') {
            throw new CarrierException('Nhập token API của shop GHN.', authFailed: true);
        }
        $data = (array) $this->client->post($token, $environment, '/v2/shop/all', ['offset' => 0, 'limit' => 200, 'client_phone' => '']);
        $shops = (array) ($data['shops'] ?? []);
        if ($shops === []) {
            throw new CarrierException('Tài khoản GHN chưa có cửa hàng nào. Tạo cửa hàng trên GHN trước.');
        }
        $locations = array_values(array_map(fn ($s) => [
            'id' => (string) $s['_id'],
            'name' => (string) ($s['name'] ?? 'Cửa hàng GHN'),
            'address' => $s['address'] ?? null,
            'phone' => $s['phone'] ?? null,
            'district_id' => isset($s['district_id']) ? (int) $s['district_id'] : null,
            'ward_code' => isset($s['ward_code']) ? (string) $s['ward_code'] : null,
        ], $shops));

        return new AccountInfo('GHN · '.$locations[0]['name'], (string) ($data['client_id'] ?? $locations[0]['phone'] ?? $locations[0]['id']), $locations);
    }

    public function quote(CarrierAccount $account, array $pickup, ShipmentDraft $draft): array
    {
        [$token, $env] = $this->auth($account);
        $to = $this->addresses->resolve($token, $env, $draft->toProvinceCode, $draft->toWardCode);
        $services = (array) $this->client->post($token, $env, '/v2/shipping-order/available-services', [
            'shop_id' => (int) $pickup['id'], 'from_district' => (int) ($pickup['district_id'] ?? 0), 'to_district' => $to['district_id'],
        ]);
        $quotes = [];
        foreach ($services as $service) {
            $id = (int) ($service['service_id'] ?? 0);
            try {
                $fee = (array) $this->client->post($token, $env, '/v2/shipping-order/fee', [
                    'service_id' => $id, 'insurance_value' => $this->vnd($draft->insuredValue), 'cod_value' => $this->vnd($draft->codAmount),
                    'to_district_id' => $to['district_id'], 'to_ward_code' => $to['ward_code'], 'weight' => $draft->weightG,
                    'length' => $draft->lengthCm ?? 10, 'width' => $draft->widthCm ?? 10, 'height' => $draft->heightCm ?? 10,
                ], (string) $pickup['id']);
            } catch (CarrierException) {
                continue; // a service that does not serve this route
            }
            $quotes[] = new ServiceQuote((string) $id, (string) ($service['short_name'] ?? 'GHN'), number_format((float) ($fee['total'] ?? 0), 2, '.', ''), $this->leadtime($token, $env, $pickup, $to, $id));
        }
        if ($quotes === []) {
            throw new CarrierException('GHN không có dịch vụ cho tuyến này.');
        }

        return $quotes;
    }

    public function create(CarrierAccount $account, array $pickup, ShipmentDraft $draft, string $service, string $clientCode): CreatedShipment
    {
        [$token, $env] = $this->auth($account);
        if (blank($draft->toName) || blank($draft->toPhone)) {
            throw new CarrierException('Thiếu tên hoặc số điện thoại người nhận.');
        }
        $to = $this->addresses->resolve($token, $env, $draft->toProvinceCode, $draft->toWardCode);
        $data = (array) $this->client->post($token, $env, '/v2/shipping-order/create', [
            'payment_type_id' => $draft->payer === 'buyer' ? 2 : 1,
            'required_note' => self::REQUIRED_NOTES[$draft->requiredNote] ?? 'CHOXEMHANGKHONGTHU',
            'note' => $draft->note,
            'client_order_code' => $clientCode,
            'to_name' => $draft->toName,
            'to_phone' => $draft->toPhone,
            'to_address' => $draft->toAddress ?? $draft->toStreet,
            'to_ward_code' => $to['ward_code'],
            'to_district_id' => $to['district_id'],
            'cod_amount' => $this->vnd($draft->codAmount),
            'insurance_value' => min(5000000, $this->vnd($draft->insuredValue)),
            'weight' => $draft->weightG,
            'length' => $draft->lengthCm ?? 10,
            'width' => $draft->widthCm ?? 10,
            'height' => $draft->heightCm ?? 10,
            'service_id' => (int) $service,
            'service_type_id' => 2,
            'items' => array_map(fn ($i) => ['name' => $i['name'] ?: 'Hàng hóa', 'quantity' => max(1, (int) $i['quantity']), 'weight' => (int) ($i['weight_g'] ?? 0) ?: max(1, intdiv($draft->weightG, max(1, count($draft->items))))], $draft->items ?: [['name' => 'Hàng hóa', 'quantity' => 1, 'weight_g' => $draft->weightG]]),
        ], (string) $pickup['id'], sideEffect: true);

        return new CreatedShipment((string) $data['order_code'], isset($data['total_fee']) ? number_format((float) $data['total_fee'], 2, '.', '') : null, $this->time($data['expected_delivery_time'] ?? null), (string) $data['order_code']);
    }

    public function findByClientCode(CarrierAccount $account, string $clientCode): ?CreatedShipment
    {
        [$token, $env] = $this->auth($account);
        try {
            $data = (array) $this->client->post($token, $env, '/v2/shipping-order/detail-by-client-code', ['client_order_code' => $clientCode]);
        } catch (CarrierException) {
            return null;
        }

        return isset($data['order_code']) ? new CreatedShipment((string) $data['order_code'], isset($data['total_fee']) ? number_format((float) $data['total_fee'], 2, '.', '') : null, $this->time($data['leadtime'] ?? null), (string) $data['order_code']) : null;
    }

    public function cancel(CarrierAccount $account, Shipment $shipment): void
    {
        [$token, $env] = $this->auth($account);
        $pickup = $account->pickupFor($shipment->warehouse_id);
        $result = (array) $this->client->post($token, $env, '/v2/switch-status/cancel', ['order_codes' => [$shipment->tracking_number]], $pickup['id'] ?? null);
        $row = collect($result)->firstWhere('order_code', $shipment->tracking_number);
        if ($row !== null && isset($row['result']) && ! $row['result']) {
            throw new CarrierException('GHN không hủy được: '.($row['message'] ?? 'đơn đã được lấy hoặc đang giao.'));
        }
    }

    public function labelUrl(CarrierAccount $account, array $shipments, string $size): string
    {
        [$token, $env] = $this->auth($account);
        $data = (array) $this->client->post($token, $env, '/v2/a5/gen-token', ['order_codes' => array_values(array_map(fn (Shipment $s) => $s->tracking_number, $shipments))]);
        $page = ['a5' => 'printA5', '80x80' => 'print80x80', '52x70' => 'print52x70'][$size] ?? 'printA5';

        return GhnClient::base($env).'/a5/public-api/'.$page.'?token='.urlencode((string) $data['token']);
    }

    public function track(CarrierAccount $account, Shipment $shipment): array
    {
        [$token, $env] = $this->auth($account);
        $data = (array) $this->client->post($token, $env, '/v2/shipping-order/detail', ['order_code' => $shipment->tracking_number]);
        $events = [];
        foreach ((array) ($data['log'] ?? []) as $log) {
            $events[] = $this->event((string) $shipment->tracking_number, (string) ($log['status'] ?? ''), $this->time($log['updated_date'] ?? null) ?? Carbon::now(), 'switch_status', $log['reason'] ?? null);
        }
        if ($events === [] && filled($data['status'] ?? null)) {
            $events[] = $this->event((string) $shipment->tracking_number, (string) $data['status'], $this->time($data['updated_date'] ?? null) ?? Carbon::now(), 'switch_status', null);
        }

        return $events;
    }

    public function parseWebhook(CarrierAccount $account, Request $request): array
    {
        $b = $request->json()->all();
        if (! isset($b['OrderCode'])) {
            return [];
        }
        $type = strtolower((string) ($b['Type'] ?? 'switch_status'));
        $at = $this->time($b['Time'] ?? null) ?? Carbon::now();
        $status = in_array($type, ['create', 'switch_status'], true) ? (string) ($b['Status'] ?? '') : '';
        $base = $this->event((string) $b['OrderCode'], $status, $at, $type, $b['Reason'] ?? null);

        return [new CarrierEvent(
            trackingNumber: $base->trackingNumber,
            status: $base->status,
            rawStatus: $base->rawStatus,
            occurredAt: $at,
            key: $base->key,
            description: match ($type) {
                'update_fee' => 'GHN cập nhật phí: '.number_format((float) ($b['TotalFee'] ?? 0), 0, ',', '.').' ₫',
                'update_weight' => 'GHN cân lại: '.(int) ($b['ConvertedWeight'] ?? $b['Weight'] ?? 0).' g',
                'update_cod' => 'GHN cập nhật tiền thu hộ: '.number_format((float) ($b['CODAmount'] ?? 0), 0, ',', '.').' ₫',
                'cod' => 'GHN đã chuyển tiền thu hộ cho shop',
                default => $base->description,
            },
            fee: in_array($type, ['update_fee', 'switch_status'], true) && isset($b['TotalFee']) && (float) $b['TotalFee'] > 0 ? number_format((float) $b['TotalFee'], 2, '.', '') : null,
            codCollected: null,
            weightG: $type === 'update_weight' && isset($b['ConvertedWeight']) ? (int) $b['ConvertedWeight'] : null,
            clientCode: isset($b['ClientOrderCode']) && $b['ClientOrderCode'] !== '' ? (string) $b['ClientOrderCode'] : null,
        )];
    }

    private function event(string $tracking, string $raw, Carbon $at, string $type, ?string $reason): CarrierEvent
    {
        $raw = strtolower($raw);

        return new CarrierEvent(
            trackingNumber: $tracking,
            status: $raw !== '' ? (self::STATUS_MAP[$raw] ?? null) : null,
            rawStatus: $raw !== '' ? $raw : null,
            occurredAt: $at,
            key: 'ghn:'.$type.':'.$raw.':'.$at->getTimestamp(),
            description: trim((self::STATUS_LABELS[$raw] ?? $raw).($reason ? ': '.$reason : '')) ?: null,
        );
    }

    private function leadtime(string $token, string $env, array $pickup, array $to, int $serviceId): ?Carbon
    {
        try {
            $data = (array) $this->client->post($token, $env, '/v2/shipping-order/leadtime', [
                'from_district_id' => (int) ($pickup['district_id'] ?? 0), 'from_ward_code' => (string) ($pickup['ward_code'] ?? ''),
                'to_district_id' => $to['district_id'], 'to_ward_code' => $to['ward_code'], 'service_id' => $serviceId,
            ], (string) $pickup['id']);

            return $this->time($data['leadtime'] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0: string, 1: string} */
    private function auth(CarrierAccount $account): array
    {
        $token = (string) (($account->credentials ?? [])['token'] ?? '');
        if ($token === '') {
            throw new CarrierException('Kết nối GHN đã ngắt. Kết nối lại để tiếp tục.', authFailed: true);
        }

        return [$token, $account->environment];
    }

    private function vnd(string $amount): int
    {
        return (int) round((float) $amount);
    }

    private function time(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }
        try {
            return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
