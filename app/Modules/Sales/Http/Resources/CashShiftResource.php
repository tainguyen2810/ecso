<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\CashShift;
use App\Modules\Sales\Models\CashShiftMovement;
use App\Modules\Sales\Services\CashShiftService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CashShift */
class CashShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'number' => $this->number,
            'status' => $this->status,
            'cashier' => $this->whenLoaded('user', fn () => ['id' => $this->user->ulid, 'name' => $this->user->name]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? ['id' => $this->warehouse->ulid, 'name' => $this->warehouse->name] : null),
            'opened_at' => $this->opened_at->toIso8601String(),
            'opening_cash' => $this->opening_cash,
            'opening_note' => $this->opening_note,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->whenLoaded('closer', fn () => $this->closer?->name),
            'expected_cash' => $this->expected_cash,
            'counted_cash' => $this->counted_cash,
            'difference' => $this->difference,
            'handed_over' => $this->handed_over,
            'kept_in_drawer' => $this->counted_cash !== null ? bcsub((string) $this->counted_cash, (string) ($this->handed_over ?? 0), 2) : null,
            'denominations' => $this->denominations,
            'closing_note' => $this->closing_note,
            'totals' => app(CashShiftService::class)->totals($this->resource),
            'movements' => $this->whenLoaded('movements', fn () => $this->movements->map(fn (CashShiftMovement $m) => [
                'kind' => $m->kind,
                'amount' => $m->amount,
                'reason' => $m->reason,
                // A voucher when the money really came in or went out; none when moved with the store's safe.
                'voucher' => $m->cash_voucher_id && $m->relationLoaded('voucher') && $m->voucher ? ['id' => $m->voucher->ulid, 'number' => $m->voucher->number] : null,
                'created_by' => $m->creator?->name,
                'created_at' => $m->created_at?->toIso8601String(),
            ])),
        ];
    }
}
