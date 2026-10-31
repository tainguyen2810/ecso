<?php

namespace App\Modules\Orders\Services;

use App\Modules\Marketplace\Models\MarketplaceStatusMap;
use App\Modules\Orders\Domain\OrderStatus;

/**
 * raw marketplace status -> internal status, from marketplace_status_maps. A raw status that is not mapped
 * (no verified documentation yet, or a new status) becomes UNKNOWN_STATUS; it is never guessed.
 */
class StatusMapper
{
    /** @var array<string, array<string, string>> */
    private array $maps = [];

    public function map(string $marketplace, string $rawStatus): string
    {
        $this->maps[$marketplace] ??= MarketplaceStatusMap::query()->where('marketplace', $marketplace)
            ->pluck('normalized_status', 'raw_status')->all();

        $status = $this->maps[$marketplace][$rawStatus] ?? null;

        return $status !== null && in_array($status, OrderStatus::ALL, true) ? $status : OrderStatus::UNKNOWN_STATUS;
    }
}
