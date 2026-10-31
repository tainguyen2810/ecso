<?php

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MarketplaceAccount */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'marketplace' => $this->marketplace,
            'marketplace_name' => config("marketplaces.catalog.{$this->marketplace}", $this->marketplace),
            'shop_name' => $this->shop_name,
            'external_shop_id' => $this->external_shop_id,
            'status' => $this->status,
            'sync_enabled' => $this->sync_enabled,
            'warehouse' => $this->warehouse ? ['id' => $this->warehouse->ulid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'circuit_open' => $this->circuitOpen(),
            'stats' => $this->when(isset($this->listings_total), fn () => [
                'listings' => (int) $this->listings_total,
                'mapped' => (int) $this->listings_mapped,
                'suggested' => (int) $this->listings_suggested,
                'push_errors' => (int) $this->push_errors,
                'pending_push' => (int) $this->pending_push,
            ]),
        ];
    }
}
