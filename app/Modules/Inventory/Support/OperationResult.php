<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\StockMovement;

final class OperationResult
{
    /**
     * @param  array<int, StockMovement>  $movements
     */
    public function __construct(public readonly bool $applied, public readonly array $movements = []) {}

    public static function duplicate(): self
    {
        return new self(false);
    }
}
