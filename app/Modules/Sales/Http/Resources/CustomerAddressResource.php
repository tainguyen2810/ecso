<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerAddress */
class CustomerAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'label' => $this->label,
            'recipient' => $this->recipient,
            'phone' => $this->phone,
            'address' => $this->address,
            'street' => $this->street,
            'province_code' => $this->province_code,
            'ward_code' => $this->ward_code,
        ];
    }
}
