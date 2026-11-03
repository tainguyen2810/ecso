<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\OrderPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderPayment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'kind' => $this->kind,
            'method' => $this->method,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'note' => $this->note,
            'paid_at' => $this->paid_at->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'order' => $this->whenLoaded('order', fn () => ['id' => $this->order->ulid, 'number' => $this->order->external_order_id]),
        ];
    }
}
