<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Cards that may be the same buyer, for a person to check and merge by hand. Nothing is ever merged automatically.
 *
 *  - same email or same tax code: very likely the same buyer;
 *  - same name: only a hint (two people can share a name), shown last and marked as such.
 *
 * The phone needs no check: it is unique per business already.
 */
class CustomerDuplicates
{
    public const LIMIT = 50;

    /**
     * @return list<array{reason: string, value: string, customers: list<array<string, mixed>>}>
     */
    public function groups(): array
    {
        $groups = [];
        foreach (['email' => 'email_key', 'tax_code' => 'tax_code', 'name' => 'LOWER(TRIM(name))'] as $reason => $expr) {
            $keys = Customer::query()->selectRaw("{$expr} as k")->whereRaw("{$expr} IS NOT NULL AND {$expr} <> ''")
                ->groupByRaw($expr)->havingRaw('COUNT(*) > 1')->orderByRaw('COUNT(*) DESC')->limit(self::LIMIT)->pluck('k');
            foreach ($keys as $key) {
                $cards = Customer::query()->withStats()->whereRaw("{$expr} = ?", [$key])->orderBy('customers.id')->limit(10)->get();
                // A pair already shown for a surer reason is not repeated under the name.
                $ids = $cards->pluck('id')->sort()->implode(',');
                if (isset($groups[$ids])) {
                    continue;
                }
                $groups[$ids] = [
                    'reason' => $reason,
                    'value' => (string) ($reason === 'name' ? $cards->first()?->name : $key),
                    'customers' => $cards->map(fn (Customer $c) => [
                        'id' => $c->ulid, 'code' => $c->code, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'address' => $c->address,
                        'status' => $c->status, 'orders_count' => (int) $c->orders_count, 'total_spent' => (string) $c->total_spent,
                        'debt' => bcadd((string) $c->debt, '0', 2), 'created_at' => $c->created_at?->toIso8601String(),
                    ])->all(),
                ];
                if (count($groups) >= self::LIMIT) {
                    break 2;
                }
            }
        }

        return array_values($groups);
    }

    /** How many groups there are now (for the badge on the tab), capped at the limit. */
    public function count(): int
    {
        return count(DB::query()->fromSub(Customer::query()->selectRaw('email_key as k')->whereNotNull('email_key')->groupBy('email_key')->havingRaw('COUNT(*) > 1'), 'd')->limit(self::LIMIT)->get());
    }
}
