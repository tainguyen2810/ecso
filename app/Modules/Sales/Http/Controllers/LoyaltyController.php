<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sales\Loyalty\LoyaltyService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Models\LoyaltyEntry;
use App\Modules\Sales\Models\LoyaltyTier;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantSettings;
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

        return response()->json(['data' => $this->program()]);
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
