<?php

use App\Modules\Orders\Domain\OrderStatus as S;
use App\Modules\Orders\Services\OrderIngestor;

it('allows forward moves, including skipped steps, and refuses backward ones', function (string $from, string $to, bool $allowed) {
    expect(S::canMove($from, $to))->toBe($allowed);
})->with([
    [S::READY_TO_PACK, S::PACKED, true],
    [S::READY_TO_PACK, S::DELIVERED, true],
    [S::UNPAID, S::COMPLETED, true],
    [S::HANDED_OVER, S::CANCELLED, true],
    [S::IN_TRANSIT, S::CANCELLED, false],
    [S::DELIVERED, S::READY_TO_PACK, false],
    [S::CANCELLED, S::READY_TO_PACK, false],
    [S::COMPLETED, S::RETURN_REQUESTED, true],
    [S::PACKED, S::UNKNOWN_STATUS, true],
    [S::UNKNOWN_STATUS, S::PACKED, true],
]);

it('derives the stock policy from the status', function () {
    expect(S::policy(S::UNPAID))->toBe(S::RESERVE)
        ->and(S::policy(S::PACKED))->toBe(S::RESERVE)
        ->and(S::policy(S::HANDED_OVER))->toBe(S::CONSUME)
        ->and(S::policy(S::DELIVERED))->toBe(S::CONSUME)
        ->and(S::policy(S::CANCELLED))->toBe(S::RELEASE)
        ->and(S::policy(S::UNKNOWN_STATUS))->toBe(S::NONE);
});

it('masks buyer phone numbers', function () {
    expect(OrderIngestor::maskPhone('0901 234 567'))->toBe('*******567')
        ->and(OrderIngestor::maskPhone(null))->toBeNull();
});
