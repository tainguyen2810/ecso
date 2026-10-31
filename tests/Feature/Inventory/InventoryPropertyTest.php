<?php

use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockVerifier;

/**
 * Property-based check: a long random sequence of operations (including duplicates and invalid ones)
 * must keep the ledger invariants and match a trivial in-memory model.
 */
it('keeps invariants under random operation sequences', function (int $seed) {
    mt_srand($seed);
    [$tenant] = tenantWithOwner();
    inTenant($tenant);

    $inventory = app(InventoryService::class);
    $warehouses = [defaultWarehouse($tenant), Warehouse::create(['code' => 'W2', 'name' => 'Kho 2', 'status' => 'active'])];
    $variants = [makeVariant($tenant, 'P-1'), makeVariant($tenant, 'P-2'), makeVariant($tenant, 'P-3')];

    $model = []; // "w:v" => [on_hand, reserved]
    $reservations = [];
    $usedKeys = [];
    $get = function ($w, $v) use (&$model) {
        return $model["{$w->id}:{$v->id}"] ?? [0, 0];
    };
    $set = function ($w, $v, $onHand, $reserved) use (&$model) {
        $model["{$w->id}:{$v->id}"] = [$onHand, $reserved];
    };

    for ($step = 0; $step < 150; $step++) {
        $w = $warehouses[mt_rand(0, 1)];
        $v = $variants[mt_rand(0, 2)];
        $qty = mt_rand(1, 8);
        // 15% of operations replay an earlier key: must be a no-op.
        $replay = $usedKeys !== [] && mt_rand(1, 100) <= 15;
        $key = $replay ? $usedKeys[array_rand($usedKeys)] : "op-{$step}";
        [$onHand, $reserved] = $get($w, $v);

        try {
            switch (mt_rand(0, 5)) {
                case 0:
                case 1:
                    $result = $inventory->receive($w, [['variant_id' => $v->id, 'quantity' => $qty, 'unit_cost' => mt_rand(1, 500)]], "rcv:{$key}");
                    if ($result->applied) {
                        $set($w, $v, $onHand + $qty, $reserved);
                    }
                    break;
                case 2:
                    $r = $inventory->reserve($w, $v, $qty, 'order_item', $step, "res:{$key}");
                    if ($r->wasRecentlyCreated) {
                        $reservations[] = $r;
                        $set($w, $v, $onHand, $reserved + min($qty, max(0, $onHand - $reserved)));
                    }
                    break;
                case 3:
                    if ($reservations) {
                        $r = $reservations[array_rand($reservations)]->refresh();
                        $rw = $r->warehouse_id === $warehouses[0]->id ? $warehouses[0] : $warehouses[1];
                        $rv = collect($variants)->firstWhere('id', $r->product_variant_id);
                        [$rOn, $rRes] = $get($rw, $rv);
                        $wasActive = $r->isActive();
                        if (mt_rand(0, 1)) {
                            $inventory->ship($r, "ship:{$r->id}");
                            if ($wasActive) {
                                $set($rw, $rv, $rOn - $r->quantity, $rRes - $r->quantity);
                            }
                        } else {
                            $inventory->release($r, "rel:{$r->id}");
                            if ($wasActive) {
                                $set($rw, $rv, $rOn, $rRes - $r->quantity);
                            }
                        }
                    }
                    break;
                case 4:
                    $result = $inventory->issue($w, [['variant_id' => $v->id, 'quantity' => $qty]], "iss:{$key}", null, StockMovement::DAMAGE_OUT);
                    if ($result->applied) {
                        $set($w, $v, $onHand - $qty, $reserved);
                    }
                    break;
                case 5:
                    $count = mt_rand(0, 15);
                    $applied = $inventory->adjust($w, [['variant_id' => $v->id, 'mode' => 'count', 'quantity' => $count]], "adj:{$key}", null);
                    if ($applied !== null) {
                        $set($w, $v, $count, $reserved);
                    }
                    break;
            }
            $usedKeys[] = $key;
        } catch (InsufficientStock|InvalidStockOperation) {
            // Refused operations must not change anything (checked below against the model).
        }
    }

    expect(app(StockVerifier::class)->verify($tenant->id))->toBe([]);

    foreach ($warehouses as $w) {
        foreach ($variants as $v) {
            $balance = balanceOf($w, $v);
            [$onHand, $reserved] = $get($w, $v);
            expect([$balance?->on_hand ?? 0, $balance?->reserved ?? 0])->toBe([$onHand, $reserved], "w{$w->id} v{$v->id}")
                ->and($onHand)->toBeGreaterThanOrEqual(0)
                ->and($reserved)->toBeGreaterThanOrEqual(0);
        }
    }

    expect(StockReservation::query()->where('status', 'active')->sum('quantity'))
        ->toEqual(collect($model)->sum(fn ($m) => $m[1]));
})->with([11, 42, 1337, 2026, 9001]);
