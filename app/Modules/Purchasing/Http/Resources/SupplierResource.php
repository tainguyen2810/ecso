<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'tax_code' => $this->tax_code,
            'payment_terms_days' => $this->payment_terms_days,
            'note' => $this->note,
            'is_active' => $this->is_active,
            'orders_count' => $this->when(isset($this->orders_count), fn () => (int) $this->orders_count),
            'purchased' => $this->when(isset($this->purchased), fn () => bcadd((string) $this->purchased, '0', 2)),
            'paid' => $this->when(isset($this->paid), fn () => bcadd((string) $this->paid, '0', 2)),
            'debt' => $this->when(isset($this->debt), fn () => bcadd((string) $this->debt, '0', 2)),
            'overdue' => $this->when(isset($this->overdue), fn () => bcadd((string) $this->overdue, '0', 2)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
