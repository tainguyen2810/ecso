<?php

use App\Modules\Inventory\Domain\AllocationCalculator as A;

it('computes sellable over warehouses, clamping only the total', function () {
    expect(A::sellable([['on_hand' => 10, 'reserved' => 3, 'safety_stock' => 2]]))->toBe(5)
        ->and(A::sellable([
            ['on_hand' => 10, 'reserved' => 0, 'safety_stock' => 0],
            ['on_hand' => 1, 'reserved' => 4, 'safety_stock' => 0], // short warehouse reduces the total
        ]))->toBe(7)
        ->and(A::sellable([['on_hand' => 1, 'reserved' => 5, 'safety_stock' => 0]]))->toBe(0)
        ->and(A::sellable([]))->toBe(0);
});

it('applies allocation modes', function (string $mode, ?int $value, int $sellable, int $expected) {
    expect(A::published($sellable, $mode, $value))->toBe($expected);
})->with([
    'all' => ['all', null, 17, 17],
    'percent rounds down' => ['percent', 50, 17, 8],
    'percent 0' => ['percent', 0, 17, 0],
    'percent 100' => ['percent', 100, 17, 17],
    'fixed below sellable' => ['fixed', 5, 17, 5],
    'fixed capped by sellable' => ['fixed', 50, 17, 17],
    'negative sellable' => ['all', null, -3, 0],
]);

it('rejects invalid allocation values', function (string $mode, ?int $value) {
    expect(fn () => A::published(10, $mode, $value))->toThrow(InvalidArgumentException::class);
})->with([['percent', 101], ['percent', -1], ['percent', null], ['fixed', -1], ['fixed', null], ['weird', 1]]);

it('never publishes more than sellable (randomised)', function () {
    mt_srand(7);
    for ($i = 0; $i < 1000; $i++) {
        $sellable = mt_rand(-5, 500);
        $mode = ['all', 'percent', 'fixed'][mt_rand(0, 2)];
        $value = $mode === 'percent' ? mt_rand(0, 100) : mt_rand(0, 600);
        $published = A::published($sellable, $mode, $value);
        expect($published)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(max(0, $sellable));
    }
});

it('holds the buffer back first, then applies the mode, then the cap', function () {
    $rule = fn (string $mode, ?int $value = null, int $buffer = 0, ?int $max = null) => ['mode' => $mode, 'value' => $value, 'buffer_qty' => $buffer, 'max_qty' => $max];

    expect(A::publish(100, $rule('all')))->toBe(100)
        ->and(A::publish(100, $rule('percent', 80, 2)))->toBe(78)
        ->and(A::publish(100, $rule('percent', 50, 0, 30)))->toBe(30)
        ->and(A::publish(5, $rule('fixed', 10)))->toBe(5)
        ->and(A::publish(1, $rule('percent', 80, 2)))->toBe(0)
        ->and(A::publish(40, $rule('none')))->toBe(0)
        ->and(A::publish(-3, $rule('all')))->toBe(0);
});
