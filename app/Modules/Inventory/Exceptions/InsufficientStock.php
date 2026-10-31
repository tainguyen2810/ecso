<?php

namespace App\Modules\Inventory\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly string $sku,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct("Không đủ tồn cho SKU {$sku}: cần {$requested}, khả dụng {$available}.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'INSUFFICIENT_STOCK',
            'sku' => $this->sku,
            'requested' => $this->requested,
            'available' => $this->available,
        ], 422);
    }
}
