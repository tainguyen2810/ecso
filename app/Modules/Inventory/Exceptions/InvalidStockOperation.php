<?php

namespace App\Modules\Inventory\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InvalidStockOperation extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'INVALID_STOCK_OPERATION'], 422);
    }
}
