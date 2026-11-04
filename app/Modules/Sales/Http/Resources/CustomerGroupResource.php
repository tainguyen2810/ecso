<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\CustomerGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerGroup */
class CustomerGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'price_tier' => $this->price_tier,
            'discount_percent' => (string) $this->discount_percent,
            'payment_method' => $this->payment_method,
            'is_active' => (bool) ($this->is_active ?? true),
            'payment_terms_days' => $this->payment_terms_days,
            'credit_limit' => $this->credit_limit !== null ? (string) $this->credit_limit : null,
            'customers_count' => $this->customers_count !== null ? (int) $this->customers_count : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
