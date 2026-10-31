<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockMovement */
class MovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'warehouse' => ['id' => $this->warehouse->ulid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name],
            'on_hand_delta' => $this->on_hand_delta,
            'reserved_delta' => $this->reserved_delta,
            'on_hand_after' => $this->on_hand_after,
            'reserved_after' => $this->reserved_after,
            'available_after' => $this->on_hand_after - $this->reserved_after,
            'unit_cost' => $this->unit_cost,
            'reference' => $this->reference_type ? ['type' => $this->reference_type, 'number' => $this->reference_number ?? null] : null,
            'reason' => $this->reason,
            'actor' => $this->actor_type === 'user' ? $this->actor?->name : $this->actor_type,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
