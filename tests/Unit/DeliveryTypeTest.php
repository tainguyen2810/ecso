<?php

use App\Modules\Orders\Support\DeliveryType as D;

it('groups the delivery type the buyer chose', function (?string $option, ?string $carrier, ?string $group) {
    expect(D::classify($option, $carrier))->toBe($group);
})->with([
    ['Hỏa Tốc', 'SPX Instant', D::EXPRESS],
    ['Hoả tốc - 4 Giờ', null, D::EXPRESS],
    ['Nhanh', 'SPX Express', D::STANDARD],
    ['Tiết kiệm', 'SPX Express', D::ECONOMY],
    ['Hàng Cồng Kềnh', 'Giao Hàng Nhanh', D::BULKY],
    ['Standard shipping', 'J&T Express', D::STANDARD],
    ['Economy shipping', null, D::ECONOMY],
    ['Giao trong ngày', null, D::EXPRESS],
    ['Gói đặc biệt', null, D::OTHER],
    // Old orders without the buyer's choice: only the carrier's name, and only where it says the type.
    [null, 'SPX Instant', D::EXPRESS],
    [null, 'SPX Express', D::STANDARD],
    [null, 'Giao Hàng Tiết Kiệm', null],
    [null, 'Giao Hàng Nhanh', null],
    [null, null, null],
]);
