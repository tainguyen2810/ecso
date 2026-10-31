<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Warehouse */
class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'is_default' => (bool) $this->is_default,
            'status' => $this->status,
            'sku_count' => $this->when(isset($this->sku_count), fn () => (int) $this->sku_count),
            'on_hand' => $this->when(isset($this->total_on_hand), fn () => (int) $this->total_on_hand),
        ];
    }
}
