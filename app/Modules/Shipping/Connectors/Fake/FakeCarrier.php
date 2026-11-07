<?php

namespace App\Modules\Shipping\Connectors\Fake;

use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Shipping\Connectors\CarrierConnector;
use App\Modules\Shipping\Connectors\CarrierException;
use App\Modules\Shipping\Connectors\Data\AccountInfo;
use App\Modules\Shipping\Connectors\Data\CarrierEvent;
use App\Modules\Shipping\Connectors\Data\CreatedShipment;
use App\Modules\Shipping\Connectors\Data\ServiceQuote;
use App\Modules\Shipping\Connectors\Data\ShipmentDraft;
use App\Modules\Shipping\Connectors\Ghn\GhnCarrier;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "Hãng giả lập": answers locally like a real carrier would, for demos and tests (never offered in production unless
 * config('shipping.fake_enabled')). Raw statuses follow GHN's names, so the same flows are exercised.
 *
 * Test hooks: a draft note containing "[timeout]" makes the parcel and then fails as if the answer was lost;
 * "[reject]" makes the carrier refuse it. Token "bad" is refused at connect.
 */
class FakeCarrier implements CarrierConnector
{
    public const KEY = 'fake';

    /** Raw status => our shipment status (GHN's names). */
    public const STATUS_MAP = GhnCarrier::STATUS_MAP;

    public const RAW_LABELS = [
        'ready_to_pick' => 'Chờ lấy hàng', 'picking' => 'Đang lấy hàng', 'picked' => 'Đã lấy hàng', 'storing' => 'Nhập kho',
        'transporting' => 'Đang luân chuyển', 'sorting' => 'Đang phân loại', 'delivering' => 'Đang giao hàng', 'delivered' => 'Giao hàng thành công',
        'delivery_fail' => 'Giao hàng thất bại', 'waiting_to_return' => 'Chờ chuyển hoàn', 'return' => 'Chuyển hoàn', 'returning' => 'Đang hoàn hàng',
        'returned' => 'Đã trả hàng cho shop', 'cancel' => 'Hủy đơn',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Hãng giả lập';
    }

    public function credentialFields(): array
    {
        return ['token' => 'Mã thử (gõ bất kỳ)'];
    }

    public function verify(array $credentials, string $environment): AccountInfo
    {
        $token = trim((string) ($credentials['token'] ?? ''));
        if ($token === '' || $token === 'bad') {
            throw new CarrierException('Mã kết nối không đúng.', authFailed: true);
        }

        return new AccountInfo('Tài khoản giả lập', '0900000000', [
            ['id' => 'FAKE-HCM', 'name' => 'Điểm lấy hàng TP.HCM', 'address' => '1 Nguyễn Huệ, TP. Hồ Chí Minh', 'province_code' => '79'],
            ['id' => 'FAKE-HN', 'name' => 'Điểm lấy hàng Hà Nội', 'address' => '1 Tràng Tiền, Hà Nội', 'province_code' => '01'],
        ]);
    }

    public function quote(CarrierAccount $account, array $pickup, ShipmentDraft $draft): array
    {
        $this->assertAddress($draft);
        $steps = max(1, (int) ceil($draft->weightG / 500));
        $far = ($pickup['province_code'] ?? null) !== $draft->toProvinceCode;
        $base = 15000 + ($steps - 1) * 5000 + ($far ? 8000 : 0);
        $now = Carbon::now();

        return [
            new ServiceQuote('standard', 'Tiêu chuẩn', number_format($base, 2, '.', ''), $now->copy()->addDays($far ? 3 : 1)->startOfDay()),
            new ServiceQuote('express', 'Nhanh', number_format((int) round($base * 1.5, -2), 2, '.', ''), $now->copy()->addDays($far ? 2 : 0)->endOfDay()->startOfMinute()),
        ];
    }

    public function create(CarrierAccount $account, array $pickup, ShipmentDraft $draft, string $service, string $clientCode): CreatedShipment
    {
        $this->assertAddress($draft);
        if (str_contains((string) $draft->note, '[reject]')) {
            throw new CarrierException('Hãng từ chối: số điện thoại người nhận không hợp lệ.');
        }
        $quote = collect($this->quote($account, $pickup, $draft))->firstWhere('code', $service)
            ?? throw new CarrierException('Dịch vụ không còn được hỗ trợ cho tuyến này.');
        $created = new CreatedShipment('FAKE'.strtoupper(substr(hash('sha256', $clientCode), 0, 8)), $quote->fee, $quote->expectedAt, 'F-'.$clientCode);
        Cache::put($this->cacheKey($clientCode), [$created->trackingNumber, $created->fee, $created->expectedAt?->toIso8601String()], now()->addDay());

        if (str_contains((string) $draft->note, '[timeout]')) {
            throw new CarrierException('Hãng không trả lời kịp.', uncertain: true);
        }

        return $created;
    }

    public function findByClientCode(CarrierAccount $account, string $clientCode): ?CreatedShipment
    {
        $hit = Cache::get($this->cacheKey($clientCode));

        return $hit ? new CreatedShipment($hit[0], $hit[1], $hit[2] ? Carbon::parse($hit[2]) : null, 'F-'.$clientCode) : null;
    }

    public function cancel(CarrierAccount $account, Shipment $shipment): void
    {
        if (in_array($shipment->status, ShipmentStatus::WITH_CARRIER, true)) {
            throw new CarrierException('Hãng đã lấy hàng, không hủy được.');
        }
    }

    public function labelUrl(CarrierAccount $account, array $shipments, string $size): string
    {
        throw new CarrierException('Hãng giả lập không có nhãn in. Dùng "In phiếu" của EzSell.');
    }

    public function track(CarrierAccount $account, Shipment $shipment): array
    {
        return array_map(fn (array $e) => $this->event($e), Cache::get('fake_carrier:track:'.$shipment->tracking_number, []));
    }

    public function parseWebhook(CarrierAccount $account, Request $request): array
    {
        $body = $request->json()->all();

        return isset($body['tracking'], $body['status']) ? [$this->event($body)] : [];
    }

    /** Test hook: what tracking will answer for a parcel. @param  array<int, array<string, mixed>>  $events */
    public static function setHistory(string $tracking, array $events): void
    {
        Cache::put('fake_carrier:track:'.$tracking, $events, now()->addDay());
    }

    private function event(array $e): CarrierEvent
    {
        $raw = (string) $e['status'];
        $at = isset($e['time']) ? Carbon::parse((string) $e['time']) : Carbon::now();

        return new CarrierEvent(
            trackingNumber: (string) $e['tracking'],
            status: self::STATUS_MAP[$raw] ?? null,
            rawStatus: $raw,
            occurredAt: $at,
            key: 'fake:'.$raw.':'.$at->getTimestamp(),
            description: $e['reason'] ?? (self::RAW_LABELS[$raw] ?? $raw),
            fee: isset($e['fee']) ? number_format((float) $e['fee'], 2, '.', '') : null,
            codCollected: isset($e['cod']) ? number_format((float) $e['cod'], 2, '.', '') : null,
            weightG: isset($e['weight']) ? (int) $e['weight'] : null,
        );
    }

    private function assertAddress(ShipmentDraft $draft): void
    {
        if (blank($draft->toProvinceCode) || blank($draft->toWardCode)) {
            throw new CarrierException('Địa chỉ nhận thiếu tỉnh/thành hoặc phường/xã.');
        }
    }

    private function cacheKey(string $clientCode): string
    {
        return 'fake_carrier:created:'.$clientCode;
    }
}
