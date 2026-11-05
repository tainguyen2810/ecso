<?php

namespace App\Modules\Cashbook\Http\Resources;

use App\Modules\Cashbook\Models\CashAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fund with its balance. The full account number is shown only to members who manage funds; others see the
 * last 4 digits.
 *
 * @mixin CashAccount
 */
class CashAccountResource extends JsonResource
{
    /** @var array<int, string> account id => balance, set by the controller before rendering */
    public static array $balances = [];

    public function toArray(Request $request): array
    {
        $number = $this->account_number;
        $full = $request->user()?->can('manage', CashAccount::class) ?? false;
        $balances = $request->user()?->can('balances', CashAccount::class) ?? false;

        return [
            'id' => $this->ulid,
            'kind' => $this->kind,
            'name' => $this->name,
            'bank_name' => $this->bank_name,
            'account_number' => $number && ! $full ? '••••'.substr($number, -4) : $number,
            'holder' => $this->holder,
            'methods' => $this->methods ?? [],
            // Members without finance.balances see the funds, not how much is in them.
            'opening_balance' => $balances ? (float) $this->opening_balance : null,
            'opening_at' => $this->opening_at?->toIso8601String(),
            'note' => $this->note,
            'is_active' => $this->is_active,
            'balance' => ! $balances ? null : (isset(self::$balances[$this->id]) ? (float) self::$balances[$this->id] : (float) $this->opening_balance),
        ];
    }
}
