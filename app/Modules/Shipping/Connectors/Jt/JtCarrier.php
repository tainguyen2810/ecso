<?php

namespace App\Modules\Shipping\Connectors\Jt;

use App\Modules\Geo\Models\Province;
use App\Modules\Geo\Models\Ward;
use App\Modules\Inventory\Models\Warehouse;
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
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * J&T Express with the shop's own account: J&T's sales staff open the API for the shop and send four things
 * (customer code, API account, private key, API password). J&T has no list of pickup points, so each warehouse of
 * the shop is a pickup point and its address is sent as the sender.
 *
 * Built from J&T's open-platform protocol (shared by its JMS countries) because the Vietnamese documentation site
 * could not be read from here; not yet tried against a real J&T Vietnam account. Any refusal is shown word for word.
 */
class JtCarrier implements CarrierConnector
{
    public const KEY = 'jt';

    /** J&T scanTypeCode => our shipment status. */
    public const STATUS_MAP = [
        '10' => ShipmentStatus::PICKED_UP,
        '20' => ShipmentStatus::IN_TRANSIT,
        '30' => ShipmentStatus::IN_TRANSIT,
        '94' => ShipmentStatus::OUT_FOR_DELIVERY,
        '100' => ShipmentStatus::DELIVERED,
        '110' => ShipmentStatus::FAILED,
        '172' => ShipmentStatus::RETURNING,
        '173' => ShipmentStatus::RETURNED,
    ];

    /** When a scan has no code, its name decides (Vietnamese and English names J&T uses). */
    private const NAME_MAP = [
        'ký nhận chuyển hoàn' => ShipmentStatus::RETURNED, 'return signed' => ShipmentStatus::RETURNED,
        'chuyển hoàn' => ShipmentStatus::RETURNING, 'hoàn hàng' => ShipmentStatus::RETURNING, 'return' => ShipmentStatus::RETURNING,
        'ký nhận' => ShipmentStatus::DELIVERED, 'signed' => ShipmentStatus::DELIVERED, 'delivered' => ShipmentStatus::DELIVERED,
        'phát hàng' => ShipmentStatus::OUT_FOR_DELIVERY, 'delivery' => ShipmentStatus::OUT_FOR_DELIVERY,
        'kiện vấn đề' => ShipmentStatus::FAILED, 'problem' => ShipmentStatus::FAILED,
        'nhận hàng' => ShipmentStatus::PICKED_UP, 'pick up' => ShipmentStatus::PICKED_UP, 'picked' => ShipmentStatus::PICKED_UP,
        'gửi kiện' => ShipmentStatus::IN_TRANSIT, 'đến kiện' => ShipmentStatus::IN_TRANSIT, 'departure' => ShipmentStatus::IN_TRANSIT, 'arrival' => ShipmentStatus::IN_TRANSIT,
    ];

    private const REQUIRED_NOTES = [
        ShipmentDraft::NOTE_VIEW_NO_TRY => 'Cho xem hàng, không cho thử',
        ShipmentDraft::NOTE_TRY => 'Cho thử hàng',
        ShipmentDraft::NOTE_NO_VIEW => 'Không cho xem hàng',
    ];

    public function __construct(private readonly JtClient $client) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'J&T Express';
    }

    public function credentialFields(): array
    {
        return [
            'customer_code' => 'Mã khách hàng (customerCode)',
            'api_account' => 'API Account',
            'private_key' => 'Private Key',
            'api_password' => 'Mật khẩu API (J&T cấp cho API, không phải mật khẩu đăng nhập)',
        ];
    }

    /**
     * J&T has no "who am I" call: cancelling an order that does not exist tells a good key (J&T answers "no such
     * order") from a wrong one (a signature or password error). The shop's warehouses become the pickup points.
     */
    public function verify(array $credentials, string $environment): AccountInfo
    {
        foreach (array_keys($this->credentialFields()) as $field) {
            if (blank($credentials[$field] ?? null)) {
                throw new CarrierException('Nhập đủ 4 thông tin J&T cấp: mã khách hàng, API Account, Private Key và mật khẩu API.', authFailed: true);
            }
        }
        $this->client->call($credentials, $environment, 'order/cancelOrder', [
            ...$this->business($credentials),
            'orderType' => '1',
            'txlogisticId' => 'EZCHECK'.Str::upper(Str::random(10)),
            'reason' => 'Kiểm tra kết nối',
        ], allowRefusal: true);

        $locations = $this->warehouseLocations();
        if ($locations === []) {
            throw new CarrierException('Shop chưa có kho có địa chỉ. Thêm địa chỉ kho (Cấu hình → Kho) để J&T biết lấy hàng ở đâu.');
        }

        return new AccountInfo('J&T · '.$credentials['customer_code'], (string) $credentials['customer_code'], $locations);
    }

    /**
     * J&T's price check; when the account has no access to it, one standard service without a price is offered so
     * the parcel can still be pushed (the fee then comes from J&T's own bill).
     */
    public function quote(CarrierAccount $account, array $pickup, ShipmentDraft $draft): array
    {
        $creds = $this->creds($account);
        $to = $this->receiver($draft);
        try {
            $data = $this->client->call($creds, $account->environment, 'spmComCost/getComCost', [
                ...$this->business($creds),
                'weight' => $this->kg($draft->weightG),
                'length' => $draft->lengthCm ?? 0,
                'width' => $draft->widthCm ?? 0,
                'height' => $draft->heightCm ?? 0,
                'senderProv' => $pickup['prov'] ?? '',
                'senderCity' => $pickup['city'] ?? '',
                'senderArea' => $pickup['area'] ?? '',
                'receiverProv' => $to['prov'],
                'receiverCity' => $to['city'],
                'receiverArea' => $to['area'],
                'codMoney' => $this->vnd($draft->codAmount),
                'offerFee' => $this->vnd($draft->insuredValue),
            ])['data'];
        } catch (CarrierException $e) {
            if ($e->authFailed) {
                throw $e;
            }
            $data = null;
        }
        $fee = $this->money(is_array($data) ? ($data['totalFee'] ?? $data['sumFreight'] ?? $data['freight'] ?? null) : (is_numeric($data) ? $data : null));

        return [new ServiceQuote('EZ', $fee !== null ? 'Chuyển phát tiêu chuẩn' : 'Chuyển phát tiêu chuẩn (giá theo hợp đồng J&T)', $fee ?? '0.00')];
    }

    public function create(CarrierAccount $account, array $pickup, ShipmentDraft $draft, string $service, string $clientCode): CreatedShipment
    {
        $creds = $this->creds($account);
        if (blank($draft->toName) || blank($draft->toPhone)) {
            throw new CarrierException('Thiếu tên hoặc số điện thoại người nhận.');
        }
        $items = $draft->items ?: [['name' => 'Hàng hóa', 'quantity' => 1, 'weight_g' => $draft->weightG]];
        $cod = $this->vnd($draft->codAmount);
        $note = trim((self::REQUIRED_NOTES[$draft->requiredNote] ?? self::REQUIRED_NOTES[ShipmentDraft::NOTE_VIEW_NO_TRY]).($draft->note ? '. '.$draft->note : ''));
        $data = (array) $this->client->call($creds, $account->environment, 'order/addOrder', [
            ...$this->business($creds),
            'txlogisticId' => $clientCode,
            'expressType' => $service !== '' ? $service : 'EZ',
            'orderType' => '1',
            'serviceType' => '01', // J&T picks up at the sender
            'deliveryType' => '04',
            // PP_PM: the shop pays on its monthly bill; CC_CASH: the receiver pays the fee.
            'payType' => $draft->payer === 'buyer' ? 'CC_CASH' : 'PP_PM',
            'sender' => [
                'name' => (string) ($pickup['contact'] ?? $pickup['name'] ?? ''),
                'mobile' => (string) ($pickup['phone'] ?? ''),
                'phone' => (string) ($pickup['phone'] ?? ''),
                'countryCode' => 'VNM',
                'prov' => (string) ($pickup['prov'] ?? ''),
                'city' => (string) ($pickup['city'] ?? ''),
                'area' => (string) ($pickup['area'] ?? ''),
                'address' => (string) ($pickup['address'] ?? ''),
            ],
            'receiver' => [
                'name' => $draft->toName,
                'mobile' => $draft->toPhone,
                'phone' => $draft->toPhone,
                'countryCode' => 'VNM',
                ...$this->receiver($draft),
            ],
            'goodsType' => 'ITN1',
            'weight' => $this->kg($draft->weightG),
            'length' => $draft->lengthCm ?? 0,
            'width' => $draft->widthCm ?? 0,
            'height' => $draft->heightCm ?? 0,
            'totalQuantity' => array_sum(array_map(fn ($i) => max(1, (int) $i['quantity']), $items)),
            'itemsValue' => $cod, // the amount J&T collects from the receiver
            'codMoney' => $cod,
            'offerFee' => $this->vnd($draft->insuredValue),
            'priceCurrency' => 'VND',
            'remark' => mb_substr($note, 0, 200),
            'items' => array_map(fn ($i) => [
                'itemName' => mb_substr($i['name'] ?: 'Hàng hóa', 0, 100),
                'number' => max(1, (int) $i['quantity']),
                'itemType' => 'ITN1',
            ], $items),
        ], sideEffect: true)['data'];
        if (blank($data['billCode'] ?? null)) {
            throw new CarrierException('J&T không trả về mã vận đơn.', uncertain: true);
        }

        return new CreatedShipment((string) $data['billCode'], $this->money($data['sumFreight'] ?? $data['totalFee'] ?? null), null, (string) ($data['txlogisticId'] ?? $clientCode));
    }

    /** After a lost answer: J&T's order lookup by our own code (txlogisticId). */
    public function findByClientCode(CarrierAccount $account, string $clientCode): ?CreatedShipment
    {
        $creds = $this->creds($account);
        try {
            $data = $this->client->call($creds, $account->environment, 'order/getOrders', [...$this->business($creds), 'serialNumber' => [$clientCode]])['data'];
        } catch (CarrierException $e) {
            if ($e->authFailed) {
                throw $e;
            }

            return null;
        }
        $row = is_array($data) ? (array_is_list($data) ? ($data[0] ?? null) : $data) : null;

        return is_array($row) && filled($row['billCode'] ?? null) ? new CreatedShipment((string) $row['billCode'], null, null, $clientCode) : null;
    }

    public function cancel(CarrierAccount $account, Shipment $shipment): void
    {
        $creds = $this->creds($account);
        $this->client->call($creds, $account->environment, 'order/cancelOrder', [
            ...$this->business($creds),
            'orderType' => '1',
            'txlogisticId' => (string) ($shipment->client_code ?: $shipment->tracking_number),
            'reason' => 'Shop hủy vận đơn',
        ]);
    }

    /**
     * J&T answers one label per call, as a link or as a PDF (base64). PDFs are kept for an hour and served through a
     * signed link, so the label (with the receiver's address) is never public.
     */
    public function labelUrl(CarrierAccount $account, array $shipments, string $size): string
    {
        $creds = $this->creds($account);
        $shipment = $shipments[0] ?? throw new CarrierException('Chưa chọn vận đơn.');
        if (count($shipments) > 1) {
            throw new CarrierException('J&T in từng nhãn một. Chọn từng vận đơn J&T để in.');
        }
        $data = (array) $this->client->call($creds, $account->environment, 'order/printOrder', [
            ...$this->business($creds),
            'billCode' => (string) $shipment->tracking_number,
            'txlogisticId' => (string) $shipment->client_code,
        ])['data'];
        $url = $data['urlContent'] ?? $data['url'] ?? null;
        if (is_string($url) && str_starts_with($url, 'http')) {
            return $url;
        }
        $pdf = base64_decode((string) ($data['base64EncodeContent'] ?? $data['content'] ?? ''), true);
        if ($pdf === false || $pdf === '') {
            throw new CarrierException('J&T không trả về nhãn in.');
        }
        $disk = Storage::disk('local');
        // Labels whose link has expired are of no use: drop them while we are here.
        foreach ($disk->files('carrier-labels') as $old) {
            if ($disk->lastModified($old) < now()->subHours(2)->getTimestamp()) {
                $disk->delete($old);
            }
        }
        $name = Str::random(40).'.pdf';
        $disk->put('carrier-labels/'.$name, $pdf);

        return URL::temporarySignedRoute('carrier-labels.show', now()->addHour(), ['file' => $name]);
    }

    public function track(CarrierAccount $account, Shipment $shipment): array
    {
        $creds = $this->creds($account);
        try {
            $data = $this->client->call($creds, $account->environment, 'logistics/trace', ['billCodes' => (string) $shipment->tracking_number])['data'];
        } catch (CarrierException $e) {
            if ($e->authFailed) {
                throw $e;
            }

            return [];
        }

        return $this->events(is_array($data) ? $data : [], (string) $shipment->tracking_number);
    }

    /** J&T pushes a form with bizContent (signed like our calls) holding one parcel's scans. */
    public function parseWebhook(CarrierAccount $account, Request $request): array
    {
        $raw = (string) $request->input('bizContent', '');
        $digest = (string) $request->header('digest', '');
        $key = (string) (($account->credentials ?? [])['private_key'] ?? '');
        if ($raw === '' || ($digest !== '' && ! hash_equals(JtClient::headerDigest($raw, $key), $digest))) {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $this->events($data, null) : [];
    }

    /**
     * @param  array<mixed>  $data  one parcel {billCode, details: [...]}, or a list of them
     * @return array<int, CarrierEvent>
     */
    private function events(array $data, ?string $tracking): array
    {
        $parcels = array_is_list($data) ? $data : [$data];
        $events = [];
        foreach ($parcels as $parcel) {
            if (! is_array($parcel)) {
                continue;
            }
            $bill = (string) ($parcel['billCode'] ?? $tracking ?? '');
            if ($bill === '') {
                continue;
            }
            $details = isset($parcel['details']) && is_array($parcel['details']) ? $parcel['details'] : [$parcel];
            foreach ($details as $d) {
                if (! is_array($d) || (blank($d['scanTypeCode'] ?? null) && blank($d['scanType'] ?? null))) {
                    continue;
                }
                $code = (string) ($d['scanTypeCode'] ?? '');
                $name = trim((string) ($d['scanType'] ?? $d['scanTypeName'] ?? ''));
                $at = $this->time($d['scanTime'] ?? null) ?? Carbon::now();
                $desc = trim((string) ($d['desc'] ?? $d['description'] ?? ''));
                $events[] = new CarrierEvent(
                    trackingNumber: $bill,
                    status: self::STATUS_MAP[$code] ?? $this->statusByName($name),
                    rawStatus: $code !== '' ? $code : mb_substr($name, 0, 50),
                    occurredAt: $at,
                    key: 'jt:'.($code !== '' ? $code : md5($name)).':'.$at->getTimestamp(),
                    description: mb_substr(($name !== '' ? $name : 'Trạng thái '.$code).($desc !== '' && $desc !== $name ? ': '.$desc : ''), 0, 255),
                    clientCode: filled($parcel['txlogisticId'] ?? null) ? (string) $parcel['txlogisticId'] : null,
                );
            }
        }

        return $events;
    }

    private function statusByName(string $name): ?string
    {
        $lower = mb_strtolower($name);
        foreach (self::NAME_MAP as $needle => $status) {
            if ($lower !== '' && str_contains($lower, $needle)) {
                return $status;
            }
        }

        return null;
    }

    /** @return array{customerCode: string, digest: string} */
    private function business(array $creds): array
    {
        return [
            'customerCode' => (string) $creds['customer_code'],
            'digest' => JtClient::businessDigest((string) $creds['customer_code'], (string) $creds['api_password'], (string) $creds['private_key']),
        ];
    }

    /** @return array<string, string> */
    private function creds(CarrierAccount $account): array
    {
        $creds = (array) ($account->credentials ?? []);
        foreach (['customer_code', 'api_account', 'private_key', 'api_password'] as $field) {
            if (blank($creds[$field] ?? null)) {
                throw new CarrierException('Kết nối J&T đã ngắt. Kết nối lại để tiếp tục.', authFailed: true);
            }
        }

        return $creds;
    }

    /**
     * Each warehouse with an address is a pickup point. Since the 2025 change Vietnam has two levels (province,
     * ward): the ward goes in both "city" and "area".
     *
     * @return array<int, array<string, string|null>>
     */
    private function warehouseLocations(): array
    {
        $store = (array) TenantSettings::current()->get('store');
        $phone = (string) ($store['phone'] ?? '');

        return Warehouse::query()->orderBy('id')->get()
            ->filter(fn (Warehouse $w) => filled($w->address) || filled($w->street))
            ->map(function (Warehouse $w) use ($phone) {
                $prov = filled($w->province_code) ? Province::query()->where('code', $w->province_code)->value('name') : null;
                $ward = filled($w->ward_code) ? Ward::query()->where('code', $w->ward_code)->value('name') : null;

                return [
                    'id' => 'wh-'.$w->ulid,
                    'name' => (string) $w->name,
                    'address' => (string) ($w->address ?: $w->street),
                    'province_code' => $w->province_code,
                    'phone' => $phone,
                    'prov' => $prov,
                    'city' => $ward,
                    'area' => $ward,
                ];
            })->values()->all();
    }

    /** @return array{prov: string, city: string, area: string, address: string} */
    private function receiver(ShipmentDraft $draft): array
    {
        $prov = filled($draft->toProvinceCode) ? (string) Province::query()->where('code', $draft->toProvinceCode)->value('name') : '';
        $ward = filled($draft->toWardCode) ? (string) Ward::query()->where('code', $draft->toWardCode)->value('name') : '';
        // J&T takes province and ward in their own fields: the street part goes in the address when we have it.
        $address = trim((string) ($draft->toStreet ?: $draft->toAddress ?: ''));
        if ($address === '') {
            throw new CarrierException('Địa chỉ nhận còn trống.');
        }

        return ['prov' => $prov, 'city' => $ward, 'area' => $ward, 'address' => $address];
    }

    private function kg(int $grams): string
    {
        return number_format(max(0.01, $grams / 1000), 2, '.', '');
    }

    private function vnd(string $amount): int
    {
        return (int) round((float) $amount);
    }

    private function money(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    /** "yyyy-MM-dd HH:mm:ss" in Vietnam time. */
    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse(trim($value), 'Asia/Ho_Chi_Minh')->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
