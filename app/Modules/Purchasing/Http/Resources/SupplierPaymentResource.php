<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupplierPayment */
class SupplierPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'kind' => $this->kind ?? SupplierPayment::PAYMENT,
            'method' => $this->method,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'note' => $this->note,
            'paid_at' => $this->paid_at->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'order' => $this->whenLoaded('order', fn () => $this->order ? ['id' => $this->order->ulid, 'number' => $this->order->number] : null),
        ];
    }
}
