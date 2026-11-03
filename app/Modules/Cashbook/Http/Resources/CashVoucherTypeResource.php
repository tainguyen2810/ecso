<?php

namespace App\Modules\Cashbook\Http\Resources;

use App\Modules\Cashbook\Models\CashVoucherType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CashVoucherType */
class CashVoucherTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'direction' => $this->direction,
            'name' => $this->name,
            'counts_in_pnl' => $this->counts_in_pnl,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'vouchers_count' => $this->whenCounted('vouchers'),
        ];
    }
}
