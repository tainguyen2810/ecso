<?php

namespace App\Modules\Shipping\Connectors;

use App\Modules\Shipping\Connectors\Fake\FakeCarrier;
use App\Modules\Shipping\Connectors\Ghn\GhnCarrier;
use App\Modules\Shipping\Connectors\ViettelPost\ViettelPostCarrier;
use InvalidArgumentException;

/** The carriers ECSO can talk to. A carrier is listed once its connector exists. */
class CarrierRegistry
{
    /** @var array<string, class-string<CarrierConnector>> */
    private const CONNECTORS = [
        GhnCarrier::KEY => GhnCarrier::class,
        ViettelPostCarrier::KEY => ViettelPostCarrier::class,
        FakeCarrier::KEY => FakeCarrier::class,
    ];

    /**
     * Carriers shown on the page but not connectable yet, and why.
     *
     * @var array<string, array{label: string, note: string}>
     */
    public const UPCOMING = [
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
        $keys = [];
        foreach (array_keys(self::CONNECTORS) as $key) {
            if ($key !== FakeCarrier::KEY || config('shipping.fake_enabled')) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function get(string $carrier): CarrierConnector
    {
        if (! $this->has($carrier)) {
            throw new InvalidArgumentException("Unknown carrier {$carrier}");
        }

        return app(self::CONNECTORS[$carrier]);
    }
}
