<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerDuplicateDismissal;
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
        $dismissed = array_flip(CustomerDuplicateDismissal::query()->pluck('group_key')->all());
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
                $groupKey = self::key($cards->pluck('id')->all());
                if (isset($dismissed[$groupKey])) {
                    $groups[$ids] = null; // still blocks the same cards from coming back under the name

                    continue;
                }
                $groups[$ids] = [
                    'key' => $groupKey,
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

        return array_values(array_filter($groups));
    }

    /**
     * Key of a group: a hash of its cards, so a group marked "Không phải trùng" comes back only if its cards change.
     *
     * @param  list<int>  $ids
     */
    public static function key(array $ids): string
    {
        sort($ids);

        return sha1(implode(',', $ids));
    }

    /** Marks a group as not duplicates (the key must be one of the groups shown now). */
    public function dismiss(string $key, ?int $by): bool
    {
        $group = collect($this->groups())->firstWhere('key', $key);
        if ($group === null) {
            return false;
        }
        CustomerDuplicateDismissal::query()->firstOrCreate(['group_key' => $key], ['reason' => $group['reason'], 'value' => mb_substr($group['value'], 0, 200), 'created_by' => $by]);

        return true;
    }

    /** How many groups there are now (for the badge on the tab), capped at the limit. */
    public function count(): int
    {
        return count(DB::query()->fromSub(Customer::query()->selectRaw('email_key as k')->whereNotNull('email_key')->groupBy('email_key')->havingRaw('COUNT(*) > 1'), 'd')->limit(self::LIMIT)->get());
    }
}
