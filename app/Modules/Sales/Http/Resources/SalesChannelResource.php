<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\SalesChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalesChannel */
class SalesChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'type' => $this->type,
            'is_active' => $this->is_active,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? ['id' => $this->warehouse->ulid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'orders_count' => $this->whenCounted('orders'),
        ];
    }
}
