<?php

use App\Modules\Assistant\Services\ProductReader;
use App\Modules\Assistant\Support\Similar;

it('matches invoice names with catalogue names regardless of accents and order', function () {
    expect(Similar::score('Card wifi Intel AX210 PCIe', 'Card wifi AX210 PCIe AX210-PCIE'))->toBeGreaterThan(0.75)
        ->and(Similar::score('SSD NVMe 512GB WD SN580', 'Ổ cứng SSD WD SN580 512GB NVMe'))->toBeGreaterThan(0.75)
        ->and(Similar::score('Chuột không dây Logitech M331', 'Card wifi AX210 PCIe'))->toBeLessThan(0.4);
});

it('compares supplier names without company words', function () {
    expect(Similar::company('CÔNG TY TNHH LINH KIỆN HOÀNG GIA', 'Linh kiện Hoàng Gia'))->toBe(1.0)
        ->and(Similar::taxCode('0316 789 123-001'))->toBe('0316789123001');
});

it('rounds the suggested price up', function () {
    expect(ProductReader::price(820000, 25))->toEqual(1030000)
        ->and(ProductReader::price(60000, 30))->toEqual(78000);
});
