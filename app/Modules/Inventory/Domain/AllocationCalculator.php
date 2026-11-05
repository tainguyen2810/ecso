<?php

namespace App\Modules\Inventory\Domain;

use InvalidArgumentException;

/**
 * Pure rules for the quantity pushed to a marketplace listing (INVENTORY-ENGINE.md §6).
 * Rules per shop and SKU live in marketplace_allocations (Marketplace\Services\AllocationPolicy).
 */
final class AllocationCalculator
{
    public const MODE_ALL = 'all';

    public const MODE_PERCENT = 'percent';

    public const MODE_FIXED = 'fixed';

    /** Not sold on this shop: publishes 0. */
    public const MODE_NONE = 'none';

    public const MODES = [self::MODE_ALL, self::MODE_PERCENT, self::MODE_FIXED, self::MODE_NONE];

    /**
     * sellable = max(0, Σ (on_hand − reserved − safety_stock)) over the warehouses that feed the listing.
     * Only the total is clamped: a warehouse that is short (negative available after a stocktake) reduces what the
     * other warehouses publish, because those orders will have to be fulfilled from somewhere (conservative).
     *
     * @param  array<int, array{on_hand: int, reserved: int, safety_stock: int}>  $balances
     */
    public static function sellable(array $balances): int
    {
        $total = 0;
        foreach ($balances as $b) {
            $total += $b['on_hand'] - $b['reserved'] - $b['safety_stock'];
        }

        return max(0, $total);
    }

    public static function published(int $sellable, string $mode = self::MODE_ALL, ?int $value = null): int
    {
        $sellable = max(0, $sellable);

        return match ($mode) {
            self::MODE_ALL => $sellable,
            self::MODE_PERCENT => self::percent($sellable, $value),
            self::MODE_FIXED => min(self::nonNegative($value), $sellable),
            self::MODE_NONE => 0,
            default => throw new InvalidArgumentException("Unknown allocation mode {$mode}"),
        };
    }

    /**
     * Published quantity of one listing: the buffer is held back first, then the mode applies, then the cap.
     * published = min(max, mode(max(0, sellable − buffer))). Never more than sellable, never below 0.
     *
     * @param  array{mode: string, value: int|null, buffer_qty?: int, max_qty?: int|null}  $rule
     */
    public static function publish(int $sellable, array $rule): int
    {
        $left = max(0, $sellable - max(0, (int) ($rule['buffer_qty'] ?? 0)));
        $published = self::published($left, $rule['mode'], $rule['value']);
        $max = $rule['max_qty'] ?? null;

        return $max === null ? $published : min($published, max(0, $max));
    }

    private static function percent(int $sellable, ?int $value): int
    {
        if ($value === null || $value < 0 || $value > 100) {
            throw new InvalidArgumentException('Percent allocation must be between 0 and 100.');
        }

        return intdiv($sellable * $value, 100);
    }

    private static function nonNegative(?int $value): int
    {
        if ($value === null || $value < 0) {
            throw new InvalidArgumentException('Fixed allocation must be a non-negative integer.');
        }

        return $value;
    }
}
