<?php

namespace App\Modules\Shipping\Connectors\ViettelPost;

use App\Modules\Geo\Models\Province;
use App\Modules\Geo\Models\Ward;
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
 * Viettel Post with the shop's own account (the phone number and password of viettelpost.vn). The password is used
 * once to get Viettel Post's long-lived token (about a year), which is all that is kept. Each "kho hàng" of the
 * account (listInventory) is a pickup point. Addresses are sent as text (…Nlp endpoints), so no district/ward id
 * mapping is needed after the 2025 address change.
 *
 * Built from Viettel Post's partner documentation as used by other integrations; not yet tried against a real shop
 * account, so any refusal is shown to the shop word for word.
 */
class ViettelPostCarrier implements CarrierConnector
{
    public const KEY = 'viettelpost';

    /** Viettel Post ORDER_STATUS => our shipment status (null: kept in the history only). */
    public const STATUS_MAP = [
        -100 => ShipmentStatus::AWAITING_PICKUP, -108 => ShipmentStatus::AWAITING_PICKUP, -109 => ShipmentStatus::AWAITING_PICKUP, -110 => ShipmentStatus::AWAITING_PICKUP,
        100 => ShipmentStatus::AWAITING_PICKUP, 102 => ShipmentStatus::AWAITING_PICKUP, 103 => ShipmentStatus::AWAITING_PICKUP, 104 => ShipmentStatus::AWAITING_PICKUP,
        105 => ShipmentStatus::PICKED_UP, 200 => ShipmentStatus::PICKED_UP, 202 => ShipmentStatus::PICKED_UP,
        300 => ShipmentStatus::IN_TRANSIT, 320 => ShipmentStatus::IN_TRANSIT, 400 => ShipmentStatus::IN_TRANSIT,
        500 => ShipmentStatus::OUT_FOR_DELIVERY, 508 => ShipmentStatus::OUT_FOR_DELIVERY,
        501 => ShipmentStatus::DELIVERED,
        505 => ShipmentStatus::FAILED, 506 => ShipmentStatus::FAILED, 507 => ShipmentStatus::FAILED,
        502 => ShipmentStatus::RETURNING, 509 => ShipmentStatus::RETURNING, 515 => ShipmentStatus::RETURNING,
        504 => ShipmentStatus::RETURNED,
        101 => ShipmentStatus::CANCELLED, 107 => ShipmentStatus::CANCELLED, 201 => ShipmentStatus::CANCELLED, 503 => ShipmentStatus::CANCELLED,
    ];

    public const STATUS_LABELS = [
        100 => 'Viettel Post đã tiếp nhận đơn', 101 => 'Viettel Post từ chối nhận', 102 => 'Đơn chờ xử lý', 103 => 'Giao cho bưu cục',
        104 => 'Bưu tá đi nhận hàng', 105 => 'Bưu tá đã nhận hàng', 107 => 'Đã hủy qua API', 200 => 'Nhận tại bưu cục gốc',
        201 => 'Hủy nhập phiếu gửi', 202 => 'Sửa phiếu gửi', 300 => 'Đang vận chuyển', 320 => 'Đang vận chuyển', 400 => 'Đến bưu cục phát',
        500 => 'Đang giao hàng', 501 => 'Giao thành công', 502 => 'Chuyển hoàn về người gửi', 503 => 'Hủy theo yêu cầu khách hàng',
        504 => 'Đã hoàn về người gửi', 505 => 'Giao chưa thành công (tồn)', 506 => 'Khách không có nhà', 507 => 'Khách đến bưu cục nhận',
        508 => 'Đang giao hàng', 509 => 'Chuyển hoàn bưu cục gốc', 515 => 'Đã duyệt hoàn',
    ];

    private const REQUIRED_NOTES = [
        ShipmentDraft::NOTE_VIEW_NO_TRY => 'Cho xem hàng, không cho thử',
        ShipmentDraft::NOTE_TRY => 'Cho thử hàng',
        ShipmentDraft::NOTE_NO_VIEW => 'Không cho xem hàng',
    ];

    public function __construct(private readonly ViettelPostClient $client) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Viettel Post';
    }

    public function credentialFields(): array
    {
        return ['username' => 'Số điện thoại (tên đăng nhập) Viettel Post', 'password' => 'Mật khẩu Viettel Post'];
    }

    /**
     * With a phone number and password: logs in, trades the short session for the long-lived token and keeps only
     * that. With the kept token (Kiểm tra kết nối): just reads the account's pickup points again.
     */
    public function verify(array $credentials, string $environment): AccountInfo
    {
        $username = trim((string) ($credentials['username'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');
        $token = (string) ($credentials['token'] ?? '');
        $kept = null;
        if ($password !== '' || $token === '') {
            if ($username === '' || $password === '') {
                throw new CarrierException('Nhập số điện thoại và mật khẩu tài khoản Viettel Post.', authFailed: true);
            }
            $login = (array) $this->client->call('POST', null, $environment, '/v2/user/Login', ['USERNAME' => $username, 'PASSWORD' => $password])['data'];
            if (blank($login['token'] ?? null)) {
                throw new CarrierException('Viettel Post không trả về phiên đăng nhập. Kiểm tra số điện thoại và mật khẩu.', authFailed: true);
            }
            $long = (array) $this->client->call('POST', (string) $login['token'], $environment, '/v2/user/ownerconnect', ['USERNAME' => $username, 'PASSWORD' => $password])['data'];
            $token = (string) ($long['token'] ?? '');
            if ($token === '') {
                throw new CarrierException('Viettel Post không cấp token dài hạn cho tài khoản này.', authFailed: true);
            }
            $kept = ['token' => $token, 'username' => $username];
        }

        $rows = (array) $this->client->call('GET', $token, $environment, '/v2/user/listInventory')['data'];
        $locations = array_values(array_filter(array_map(fn ($r) => is_array($r) ? [
            'id' => (string) ($r['groupaddressId'] ?? $r['GROUPADDRESS_ID'] ?? ''),
            'name' => (string) ($r['name'] ?? $r['NAME'] ?? 'Kho Viettel Post'),
            'address' => $r['address'] ?? $r['ADDRESS'] ?? null,
            'phone' => $r['phone'] ?? $r['PHONE'] ?? null,
            'cus_id' => isset($r['cusId']) ? (string) $r['cusId'] : (isset($r['CUS_ID']) ? (string) $r['CUS_ID'] : null),
        ] : null, $rows), fn ($l) => $l !== null && $l['id'] !== ''));
        if ($locations === []) {
            throw new CarrierException('Tài khoản Viettel Post chưa có địa chỉ lấy hàng (kho hàng). Thêm trên viettelpost.vn trước.');
        }
        $account = $username !== '' ? $username : (string) ($locations[0]['phone'] ?? $locations[0]['id']);

        return new AccountInfo('Viettel Post · '.$locations[0]['name'], $account, $locations, $kept);
    }

    public function quote(CarrierAccount $account, array $pickup, ShipmentDraft $draft): array
    {
        [$token, $env] = $this->auth($account);
        $data = $this->client->call('POST', $token, $env, '/v2/order/getPriceAllNlp', [
            'SENDER_ADDRESS' => (string) ($pickup['address'] ?? ''),
            'RECEIVER_ADDRESS' => $this->receiverAddress($draft),
            'PRODUCT_TYPE' => 'HH',
            'PRODUCT_WEIGHT' => $draft->weightG,
            'PRODUCT_PRICE' => $this->vnd($draft->insuredValue),
            'MONEY_COLLECTION' => $this->vnd($draft->codAmount),
            'PRODUCT_LENGTH' => $draft->lengthCm ?? 0,
            'PRODUCT_WIDTH' => $draft->widthCm ?? 0,
            'PRODUCT_HEIGHT' => $draft->heightCm ?? 0,
            'TYPE' => 1,
        ])['data'];
        $list = is_array($data) && array_key_exists('RESULT', $data) ? (array) $data['RESULT'] : (array) $data;
        $quotes = [];
        foreach ($list as $row) {
            if (! is_array($row) || blank($row['MA_DV_CHINH'] ?? null)) {
                continue;
            }
            $quotes[] = new ServiceQuote(
                (string) $row['MA_DV_CHINH'],
                (string) ($row['TEN_DICHVU'] ?? $row['MA_DV_CHINH']),
                number_format((float) ($row['GIA_CUOC'] ?? 0), 2, '.', ''),
                $this->afterHours($row['THOI_GIAN'] ?? null),
            );
        }
        if ($quotes === []) {
            throw new CarrierException('Viettel Post không có dịch vụ cho tuyến này.');
        }
        usort($quotes, fn (ServiceQuote $a, ServiceQuote $b) => bccomp($a->fee, $b->fee, 2));

        return $quotes;
    }

    public function create(CarrierAccount $account, array $pickup, ShipmentDraft $draft, string $service, string $clientCode): CreatedShipment
    {
        [$token, $env] = $this->auth($account);
        if (blank($draft->toName) || blank($draft->toPhone)) {
            throw new CarrierException('Thiếu tên hoặc số điện thoại người nhận.');
        }
        $cod = $this->vnd($draft->codAmount);
        $items = $draft->items ?: [['name' => 'Hàng hóa', 'quantity' => 1, 'weight_g' => $draft->weightG]];
        $note = trim((self::REQUIRED_NOTES[$draft->requiredNote] ?? self::REQUIRED_NOTES[ShipmentDraft::NOTE_VIEW_NO_TRY]).($draft->note ? '. '.$draft->note : ''));
        $data = (array) $this->client->call('POST', $token, $env, '/v2/order/createOrderNlp', [
            'ORDER_NUMBER' => $clientCode,
            'CHECK_UNIQUE' => true,
            'GROUPADDRESS_ID' => (int) $pickup['id'],
            'CUS_ID' => isset($pickup['cus_id']) ? (int) $pickup['cus_id'] : null,
            'ORDER_TYPE' => 1,
            'SENDER_FULLNAME' => (string) ($pickup['name'] ?? ''),
            'SENDER_ADDRESS' => (string) ($pickup['address'] ?? ''),
            'SENDER_PHONE' => (string) ($pickup['phone'] ?? ''),
            'RECEIVER_FULLNAME' => $draft->toName,
            'RECEIVER_ADDRESS' => $this->receiverAddress($draft),
            'RECEIVER_PHONE' => $draft->toPhone,
            'PRODUCT_NAME' => mb_substr(implode(', ', array_map(fn ($i) => $i['name'] ?: 'Hàng hóa', $items)), 0, 200),
            'PRODUCT_QUANTITY' => array_sum(array_map(fn ($i) => max(1, (int) $i['quantity']), $items)),
            'PRODUCT_PRICE' => $this->vnd($draft->insuredValue),
            'PRODUCT_WEIGHT' => $draft->weightG,
            'PRODUCT_LENGTH' => $draft->lengthCm ?? 0,
            'PRODUCT_WIDTH' => $draft->widthCm ?? 0,
            'PRODUCT_HEIGHT' => $draft->heightCm ?? 0,
            'PRODUCT_TYPE' => 'HH',
            // 1 no collection, 2 goods + fee, 3 goods only, 4 fee only.
            'ORDER_PAYMENT' => $cod > 0 ? ($draft->payer === 'buyer' ? 2 : 3) : ($draft->payer === 'buyer' ? 4 : 1),
            'ORDER_SERVICE' => $service,
            'ORDER_SERVICE_ADD' => '',
            'ORDER_NOTE' => mb_substr($note, 0, 150),
            'MONEY_COLLECTION' => $cod,
            'LIST_ITEM' => array_map(fn ($i) => [
                'PRODUCT_NAME' => mb_substr($i['name'] ?: 'Hàng hóa', 0, 150),
                'PRODUCT_QUANTITY' => max(1, (int) $i['quantity']),
                'PRODUCT_PRICE' => 0,
                'PRODUCT_WEIGHT' => (int) ($i['weight_g'] ?? 0) ?: max(1, intdiv($draft->weightG, max(1, count($items)))),
            ], $items),
        ], sideEffect: true)['data'];
        if (blank($data['ORDER_NUMBER'] ?? null)) {
            throw new CarrierException('Viettel Post không trả về mã vận đơn.', uncertain: true);
        }

        return new CreatedShipment(
            (string) $data['ORDER_NUMBER'],
            isset($data['MONEY_TOTAL']) ? number_format((float) $data['MONEY_TOTAL'], 2, '.', '') : null,
            isset($data['KPI_HT']) && (float) $data['KPI_HT'] > 0 ? Carbon::now()->addMinutes((int) round((float) $data['KPI_HT'] * 60)) : null,
            (string) $data['ORDER_NUMBER'],
        );
    }

    /**
     * Viettel Post has no look-up by the shop's own order number. The number is sent with CHECK_UNIQUE, so a retry
     * after a lost answer is refused instead of making a second parcel, and the parcel's first webhook (which carries
     * our number as ORDER_REFERENCE) fills in the tracking number.
     */
    public function findByClientCode(CarrierAccount $account, string $clientCode): ?CreatedShipment
    {
        return null;
    }

    public function cancel(CarrierAccount $account, Shipment $shipment): void
    {
        [$token, $env] = $this->auth($account);
        $this->client->call('POST', $token, $env, '/v2/order/UpdateOrder', ['TYPE' => 4, 'ORDER_NUMBER' => (string) $shipment->tracking_number, 'NOTE' => 'Shop hủy vận đơn']);
    }

    public function labelUrl(CarrierAccount $account, array $shipments, string $size): string
    {
        [$token, $env] = $this->auth($account);
        $result = $this->client->call('POST', $token, $env, '/v2/order/printing-code', [
            'ORDER_ARRAY' => array_values(array_map(fn (Shipment $s) => (string) $s->tracking_number, $shipments)),
            'EXPIRY_TIME' => (int) (Carbon::now()->addHour()->getTimestamp() * 1000),
        ]);
        // The print code comes back in "message".
        $code = $result['message'] !== '' ? $result['message'] : (string) (is_string($result['data']) ? $result['data'] : '');
        if ($code === '') {
            throw new CarrierException('Viettel Post không trả về mã in nhãn.');
        }
        $type = $size === 'a5' ? 1 : 2; // 1 = A5, 2 = A6 (closest to the small thermal sizes)

        return (ViettelPostClient::PRINT_BASE[$env] ?? ViettelPostClient::PRINT_BASE[CarrierAccount::PRODUCTION]).'?type='.$type.'&bill='.urlencode($code).'&showPostage=1';
    }

    /** The parcel's current state (order detail); Viettel Post sends the step-by-step history by webhook. */
    public function track(CarrierAccount $account, Shipment $shipment): array
    {
        [$token, $env] = $this->auth($account);
        try {
            $data = (array) $this->client->call('GET', $token, $env, '/v3/order/detail-v2', ['o' => (string) $shipment->tracking_number])['data'];
        } catch (CarrierException $e) {
            if ($e->authFailed) {
                throw $e;
            }

            return [];
        }
        if (! isset($data['ORDER_STATUS'])) {
            return [];
        }

        return [$this->event($data, (string) $shipment->tracking_number)];
    }

    public function parseWebhook(CarrierAccount $account, Request $request): array
    {
        $data = $request->json('DATA');
        if (! is_array($data) || blank($data['ORDER_NUMBER'] ?? null)) {
            return [];
        }

        return [$this->event($data, (string) $data['ORDER_NUMBER'])];
    }

    /** @param  array<string, mixed>  $d  a webhook DATA block or an order detail */
    private function event(array $d, string $tracking): CarrierEvent
    {
        $code = (int) ($d['ORDER_STATUS'] ?? 0);
        $at = $this->time($d['ORDER_STATUSDATE'] ?? null) ?? Carbon::now();
        $label = trim((string) ($d['STATUS_NAME'] ?? '')) ?: (self::STATUS_LABELS[$code] ?? 'Trạng thái '.$code);
        $note = trim((string) ($d['NOTE'] ?? ''));
        $fee = isset($d['MONEY_TOTAL']) && (float) $d['MONEY_TOTAL'] > 0 ? number_format((float) $d['MONEY_TOTAL'], 2, '.', '') : null;

        return new CarrierEvent(
            trackingNumber: $tracking,
            status: self::STATUS_MAP[$code] ?? null,
            rawStatus: (string) $code,
            occurredAt: $at,
            key: 'vtp:'.$code.':'.$at->getTimestamp(),
            description: mb_substr($label.($note !== '' && $note !== $label ? ': '.$note : ''), 0, 255),
            fee: $fee,
            codCollected: $code === 501 && isset($d['MONEY_COLLECTION']) ? number_format((float) $d['MONEY_COLLECTION'], 2, '.', '') : null,
            weightG: isset($d['PRODUCT_WEIGHT']) && (int) $d['PRODUCT_WEIGHT'] > 0 ? (int) $d['PRODUCT_WEIGHT'] : null,
            clientCode: filled($d['ORDER_REFERENCE'] ?? null) ? (string) $d['ORDER_REFERENCE'] : null,
        );
    }

    /** The receiver's address as text: the order's address line, completed with the ward and province names. */
    private function receiverAddress(ShipmentDraft $draft): string
    {
        $line = trim((string) ($draft->toAddress ?: $draft->toStreet ?: ''));
        $ward = filled($draft->toWardCode) ? Ward::query()->where('code', $draft->toWardCode)->value('name') : null;
        $province = filled($draft->toProvinceCode) ? Province::query()->where('code', $draft->toProvinceCode)->value('name') : null;
        if ($line !== '' && ($province === null || str_contains(mb_strtolower($line), mb_strtolower((string) $province)))) {
            return $line;
        }
        $address = implode(', ', array_filter([$line, $ward, $province], fn ($p) => filled($p)));
        if ($address === '') {
            throw new CarrierException('Địa chỉ nhận còn trống.');
        }

        return $address;
    }

    /** "36 giờ", "2 ngày" or a number of hours => when it should arrive. */
    private function afterHours(mixed $value): ?Carbon
    {
        if (is_numeric($value)) {
            return (float) $value > 0 ? Carbon::now()->addMinutes((int) round((float) $value * 60)) : null;
        }
        if (is_string($value) && preg_match('/(\d+(?:[.,]\d+)?)\s*(giờ|ngày|h|d)/iu', $value, $m)) {
            $n = (float) str_replace(',', '.', $m[1]);
            $hours = in_array(mb_strtolower($m[2]), ['ngày', 'd'], true) ? $n * 24 : $n;

            return Carbon::now()->addMinutes((int) round($hours * 60));
        }

        return null;
    }

    /** @return array{0: string, 1: string} */
    private function auth(CarrierAccount $account): array
    {
        $token = (string) (($account->credentials ?? [])['token'] ?? '');
        if ($token === '') {
            throw new CarrierException('Kết nối Viettel Post đã ngắt. Kết nối lại để tiếp tục.', authFailed: true);
        }

        return [$token, $account->environment];
    }

    private function vnd(string $amount): int
    {
        return (int) round((float) $amount);
    }

    /** "dd/MM/yyyy HH:mm:ss" (Viettel Post's format, Vietnam time). */
    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return preg_match('#^\d{1,2}/\d{1,2}/\d{4}#', $value)
                ? Carbon::createFromFormat('d/m/Y H:i:s', trim($value), 'Asia/Ho_Chi_Minh')->setTimezone(config('app.timezone'))
                : Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
