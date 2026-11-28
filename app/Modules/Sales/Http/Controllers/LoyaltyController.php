<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Loyalty\LoyaltyService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Models\LoyaltyEntry;
use App\Modules\Sales\Models\LoyaltyTier;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantSettings;
use App\Shared\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tích điểm và hạng khách (C5): the program's settings and tiers, and each customer's points. Reading needs
 * customers.view; changing the program or giving points by hand needs promotions.manage.
 */
class LoyaltyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly LoyaltyService $loyalty) {}

    public function show(): JsonResponse
    {
        $this->can('customers.view', 'promotions.manage');

        return response()->json(['data' => $this->program(), 'meta' => ['stats' => $this->stats(), 'moves' => $this->moves()]]);
    }

    /**
     * Points in the hands of customers, earned and spent this month (vs last month), and points about to expire in
     * the next 30 days (by the same rule as the nightly expiry: oldest earned first, less what was spent).
     *
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $start = ReportRange::now()->startOfMonth();
        $sum = fn (array $kinds, $from, $to) => (int) LoyaltyEntry::query()->whereIn('kind', $kinds)
            ->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())->sum('points');
        $earnKinds = [LoyaltyEntry::EARN];
        $spendKinds = [LoyaltyEntry::REDEEM, LoyaltyEntry::REDEEM_BACK];
        $soon = now()->addDays(30);
        $rows = LoyaltyEntry::query()->toBase()->groupBy('customer_id')->selectRaw(
            'customer_id, SUM(CASE WHEN points > 0 AND expires_at IS NOT NULL AND expires_at <= ? AND kind IN (?, ?) THEN points ELSE 0 END) as due, '
            .'SUM(CASE WHEN points < 0 THEN -points ELSE 0 END) - SUM(CASE WHEN kind = ? THEN points ELSE 0 END) as used',
            [$soon, LoyaltyEntry::EARN, LoyaltyEntry::ADJUST, LoyaltyEntry::REDEEM_BACK],
        )->havingRaw('due > 0')->get();
        $balances = Customer::query()->whereIn('id', $rows->pluck('customer_id'))->pluck('loyalty_points', 'id');
        $expiring = 0;
        $expiringCustomers = 0;
        foreach ($rows as $r) {
            $points = min(max(0, (int) $r->due - (int) $r->used), max(0, (int) ($balances[$r->customer_id] ?? 0)));
            $expiring += $points;
            $expiringCustomers += $points > 0 ? 1 : 0;
        }
        $held = Customer::query()->where('loyalty_points', '>', 0)->toBase()->selectRaw('COALESCE(SUM(loyalty_points), 0) as p, COUNT(*) as n')->first();
        $end = $start->addMonth();

        return [
            'held' => (int) $held->p,
            'holders' => (int) $held->n,
            'earned' => $sum($earnKinds, $start, $end),
            'earned_before' => $sum($earnKinds, $start->subMonth(), $start),
            'earn_orders' => LoyaltyEntry::query()->where('kind', LoyaltyEntry::EARN)->where('created_at', '>=', $start->utc())->distinct()->count('order_id'),
            'spent' => -$sum($spendKinds, $start, $end),
            'spend_orders' => Order::query()->where('points_used', '>', 0)->where('status', '!=', OrderStatus::CANCELLED)->where('placed_at', '>=', $start->utc())->count(),
            'expiring' => $expiring,
            'expiring_customers' => $expiringCustomers,
        ];
    }

    /**
     * The latest tier changes (the "tier" rows of the points history), newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function moves(): array
    {
        return LoyaltyEntry::query()->where('kind', LoyaltyEntry::TIER)->latest('id')->limit(6)->get(['customer_id', 'note', 'created_at'])
            ->load('customer:id,ulid,name')
            ->map(fn (LoyaltyEntry $e) => [
                'customer' => $e->customer ? ['id' => $e->customer->ulid, 'name' => $e->customer->name] : null,
                'up' => str_starts_with((string) $e->note, 'Lên'),
                'note' => $e->note,
                'at' => $e->created_at?->toIso8601String(),
            ])->all();
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $this->can('promotions.manage');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'earn_per' => ['required', 'integer', 'min:1000', 'max:100000000'],
            'point_value' => ['required', 'integer', 'min:0', 'max:10000000'],
            'max_redeem_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'expire_months' => ['required', 'integer', 'min:0', 'max:120'],
            'with_promotions' => ['required', 'boolean'],
            'tiers' => ['array', 'max:10'],
            'tiers.*.id' => ['nullable', 'string'],
            'tiers.*.name' => ['required', 'string', 'max:60', 'distinct'],
            'tiers.*.min_spent' => ['required', 'numeric', 'min:0', 'max:999999999999', 'distinct'],
            'tiers.*.point_multiplier' => ['required', 'numeric', 'min:0', 'max:100'],
            'tiers.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tiers.*.customer_group_id' => ['nullable', 'string'],
            'tiers.*.color' => ['nullable', 'string', 'max:16'],
        ], ['tiers.*.name.distinct' => 'Hai hạng trùng tên.', 'tiers.*.min_spent.distinct' => 'Hai hạng trùng mức chi tiêu.']);

        DB::transaction(function () use ($data, $context) {
            $tenant = $context->tenant();
            $settings = $tenant->settings ?? [];
            $settings['loyalty'] = TenantSettings::merge(TenantSettings::DEFAULTS['loyalty'], collect($data)->except('tiers')->all());
            $tenant->settings = $settings;
            $tenant->save();

            $keep = [];
            foreach ($data['tiers'] ?? [] as $row) {
                $tier = filled($row['id'] ?? null) ? LoyaltyTier::query()->where('ulid', $row['id'])->first() : null;
                $tier ??= new LoyaltyTier;
                $groupId = filled($row['customer_group_id'] ?? null)
                    ? (CustomerGroup::query()->where('ulid', $row['customer_group_id'])->value('id') ?? throw ValidationException::withMessages(['tiers' => 'Nhóm khách không tồn tại.']))
                    : $tier->customer_group_id;
                // A tier with a discount needs a group to carry it: "Khách <hạng>" is made when none is chosen.
                if ($groupId === null && (float) ($row['discount_percent'] ?? 0) > 0) {
                    $group = new CustomerGroup(['name' => 'Khách '.$row['name'], 'price_tier' => 'retail', 'discount_percent' => $row['discount_percent'], 'is_active' => true]);
                    $group->code = $this->groupCode($row['name']);
                    $group->save();
                    $groupId = $group->id;
                } elseif ($groupId !== null && array_key_exists('discount_percent', $row)) {
                    CustomerGroup::query()->whereKey($groupId)->update(['discount_percent' => (float) ($row['discount_percent'] ?? 0)]);
                }
                $tier->fill([
                    'name' => $row['name'], 'min_spent' => $row['min_spent'], 'point_multiplier' => $row['point_multiplier'],
                    'customer_group_id' => $groupId, 'color' => $row['color'] ?? null,
                ])->save();
                $keep[] = $tier->id;
            }
            LoyaltyTier::query()->whereNotIn('id', $keep)->delete();
        });
        $this->audit->record('loyalty.updated', $context->tenant(), ['enabled' => $data['enabled'], 'tiers' => count($data['tiers'] ?? [])]);

        return response()->json(['data' => $this->program()]);
    }

    /** A customer's points, tier, how far the next tier is, and the history. */
    public function customer(Customer $customer): JsonResponse
    {
        $this->can('customers.view');
        $tiers = LoyaltyTier::query()->orderBy('min_spent')->get();
        $spent = $this->loyalty->spent12($customer->id);
        $tier = $tiers->firstWhere('id', $customer->loyalty_tier_id);
        $next = $tiers->first(fn (LoyaltyTier $t) => bccomp((string) $t->min_spent, $spent, 2) > 0);
        $settings = LoyaltyService::settings();
        $soon = $settings['expire_months'] > 0 ? LoyaltyEntry::query()->where('customer_id', $customer->id)->where('points', '>', 0)
            ->whereNotNull('expires_at')->where('expires_at', '>', now())->orderBy('expires_at')->first(['points', 'expires_at']) : null;
        $entries = LoyaltyEntry::query()->with('order:id,ulid,external_order_id')->where('customer_id', $customer->id)->latest('id')->limit(100)->get();

        return response()->json(['data' => [
            'enabled' => $settings['enabled'],
            'points' => (int) $customer->loyalty_points,
            'point_value' => $settings['point_value'],
            'tier' => $tier ? $this->tier($tier) : null,
            'spent_12m' => $spent,
            'next_tier' => $next ? ['name' => $next->name, 'min_spent' => $next->min_spent, 'missing' => bcsub((string) $next->min_spent, $spent, 2)] : null,
            'expiring' => $soon ? ['points' => $soon->points, 'at' => $soon->expires_at?->toIso8601String()] : null,
            'entries' => $entries->map(fn (LoyaltyEntry $e) => [
                'id' => $e->id, 'kind' => $e->kind, 'points' => $e->points, 'note' => $e->note,
                'order' => $e->order ? ['id' => $e->order->ulid, 'number' => $e->order->external_order_id] : null,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
        ]]);
    }

    public function adjust(Request $request, Customer $customer): JsonResponse
    {
        $this->can('promotions.manage');
        $data = $request->validate(['points' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'], 'note' => ['required', 'string', 'max:200']]);
        if ($data['points'] < 0 && -$data['points'] > (int) $customer->loyalty_points) {
            throw ValidationException::withMessages(['points' => "Khách chỉ còn {$customer->loyalty_points} điểm."]);
        }
        DB::transaction(fn () => $this->loyalty->adjust($customer, (int) $data['points'], $data['note']));
        $this->audit->record('loyalty.adjusted', $customer, ['points' => $data['points'], 'note' => $data['note']]);

        return $this->customer($customer->refresh());
    }

    /** @return array<string, mixed> */
    private function program(): array
    {
        return [
            ...LoyaltyService::settings(),
            'tiers' => LoyaltyTier::query()->with('customerGroup')->withCount('customers')->orderBy('min_spent')->get()->map(fn (LoyaltyTier $t) => $this->tier($t))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function tier(LoyaltyTier $t): array
    {
        $t->loadMissing('customerGroup');

        return [
            'id' => $t->ulid,
            'name' => $t->name,
            'min_spent' => $t->min_spent,
            'point_multiplier' => $t->point_multiplier,
            'color' => $t->color,
            'customer_group' => $t->customerGroup ? ['id' => $t->customerGroup->ulid, 'name' => $t->customerGroup->name] : null,
            'discount_percent' => $t->customerGroup->discount_percent ?? '0.00',
            'customers_count' => (int) ($t->customers_count ?? 0),
        ];
    }

    private function groupCode(string $name): string
    {
        $base = 'HANG-'.Str::upper(Str::limit(Str::slug($name), 20, ''));
        $code = $base;
        for ($i = 2; CustomerGroup::query()->where('code', $code)->exists(); $i++) {
            $code = "{$base}-{$i}";
        }

        return $code;
    }

    private function can(string ...$permissions): void
    {
        $ctx = app(TenantContext::class);
        abort_unless(collect($permissions)->contains(fn ($p) => $ctx->can($p)), 403);
    }
}
