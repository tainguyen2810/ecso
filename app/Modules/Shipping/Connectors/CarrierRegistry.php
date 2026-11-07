<?php

namespace App\Modules\Shipping\Connectors;

use App\Modules\Shipping\Connectors\Fake\FakeCarrier;
use App\Modules\Shipping\Connectors\Ghn\GhnCarrier;
use InvalidArgumentException;

/** The carriers ECSO can talk to. A carrier is listed only once its connector exists and was tried for real. */
class CarrierRegistry
{
    /** @var array<string, class-string<CarrierConnector>> */
    private const CONNECTORS = [
        GhnCarrier::KEY => GhnCarrier::class,
        FakeCarrier::KEY => FakeCarrier::class,
    ];

    /**
     * Carriers shown on the page but not connectable yet, and why.
     *
     * @var array<string, array{label: string, note: string}>
     */
    public const UPCOMING = [
        'viettelpost' => ['label' => 'Viettel Post', 'note' => 'Đang hoàn thiện kết nối.'],
        'jt' => ['label' => 'J&T Express', 'note' => 'Chờ J&T cấp quyền API. Trong lúc chờ: nhập mã vận đơn trên đơn hoặc nhập file.'],
        'spx' => ['label' => 'SPX Express', 'note' => 'SPX chưa mở API cho phần mềm ngoài. Đơn Shopee lấy vận đơn qua sàn; đơn tự bán nhập mã hoặc nhập file.'],
    ];

    public function has(string $carrier): bool
    {
        return in_array($carrier, $this->keys(), true);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_values(array_filter(array_keys(self::CONNECTORS), fn (string $k) => $k !== FakeCarrier::KEY || config('shipping.fake_enabled')));
    }

    public function get(string $carrier): CarrierConnector
    {
        if (! $this->has($carrier)) {
            throw new InvalidArgumentException("Unknown carrier {$carrier}");
        }

        return app(self::CONNECTORS[$carrier]);
    }
}
