<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'group' => $this->whenLoaded('customerGroup', fn () => $this->customerGroup ? [
                'id' => $this->customerGroup->ulid, 'code' => $this->customerGroup->code, 'name' => $this->customerGroup->name,
                'price_tier' => $this->customerGroup->price_tier, 'discount_percent' => (string) $this->customerGroup->discount_percent, 'payment_method' => $this->customerGroup->payment_method,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->ulid, 'name' => $this->assignee->name] : null),
            'birthday' => $this->birthday?->toDateString(),
            'gender' => $this->gender,
            'tax_code' => $this->tax_code,
            'tags' => $this->tags ?? [],
            'status' => $this->status,
            'note' => $this->note,
            'orders_count' => $this->orders_count !== null ? (int) $this->orders_count : null,
            'total_spent' => $this->total_spent !== null ? (string) $this->total_spent : null,
            'debt' => $this->debt !== null ? (string) $this->debt : null,
            'last_order_at' => $this->last_order_at ? Carbon::parse($this->last_order_at)->toIso8601String() : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
