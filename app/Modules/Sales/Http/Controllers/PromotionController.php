<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Models\Promotion;
use App\Modules\Sales\Models\PromotionUsage;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Promotions\PromotionEngine;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Khuyến mãi (C4). Anyone who sells (orders.create) sees the list and the preview on the order form; changes need
 * promotions.manage. Ids in and out are public ulids; stored as internal ids.
 */
class PromotionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->can('orders.create', 'promotions.manage');
        $stats = PromotionUsage::query()->join('orders', 'orders.id', '=', 'promotion_usages.order_id')->where('orders.status', '!=', 'CANCELLED')
            ->groupBy('promotion_usages.promotion_id')
            ->select('promotion_usages.promotion_id', DB::raw('COUNT(*) as uses'), DB::raw('SUM(promotion_usages.amount) as amount'))
            ->get()->keyBy('promotion_id');
        $list = Promotion::query()->with('giftVariant.product')->latest('id')->get()
            ->map(fn (Promotion $p) => $this->present($p, $stats[$p->id] ?? null));
        if ($state = $request->query('state')) {
            $list = $list->where('state', $state)->values();
        }

        return response()->json(['data' => $list]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can('promotions.manage');
        $promotion = new Promotion($this->validated($request, null));
        $promotion->created_by = $request->user()->id;
        $promotion->save();
        $this->audit->record('promotion.created', $promotion, ['name' => $promotion->name, 'code' => $promotion->code]);

        return response()->json(['data' => $this->present($promotion->load('giftVariant.product'), null)], 201);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $this->can('promotions.manage');
        $data = $request->has('status') && count($request->all()) === 1
            ? $request->validate(['status' => ['required', Rule::in([Promotion::ACTIVE, Promotion::PAUSED])]])
            : $this->validated($request, $promotion);
        $promotion->fill($data)->save();
        $this->audit->record('promotion.updated', $promotion, ['name' => $promotion->name, 'status' => $promotion->status]);

        return response()->json(['data' => $this->present($promotion->load('giftVariant.product'), null)]);
    }

    /** Only a promotion no order has used can be deleted; a used one is paused instead, so its history stays. */
    public function destroy(Promotion $promotion): JsonResponse
    {
        $this->can('promotions.manage');
        if (PromotionUsage::query()->where('promotion_id', $promotion->id)->exists()) {
            throw ValidationException::withMessages(['promotion' => 'Khuyến mãi đã có đơn dùng, không xóa được. Bấm "Tạm dừng" để ngừng áp dụng.']);
        }
        $this->audit->record('promotion.deleted', $promotion, ['name' => $promotion->name]);
        $promotion->delete();

        return response()->json(null, 204);
    }

    /** What an order being typed would receive (the server applies the same on save). */
    public function preview(Request $request, PromotionEngine $engine): JsonResponse
    {
        $this->can('orders.create', 'orders.update');
        $data = $request->validate([
            'channel_id' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'new_customer' => ['boolean'],
            'order_id' => ['nullable', 'string'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'promotions_off' => ['boolean'],
            'placed_at' => ['nullable', 'date'],
            'lines' => ['array', 'max:300'],
            'lines.*.variant_id' => ['nullable', 'string'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $variants = ProductVariant::query()->with('product:id,category_id')->whereIn('ulid', array_filter(array_column($data['lines'] ?? [], 'variant_id')))->get()->keyBy('ulid');
        $lines = array_map(function (array $l) use ($variants) {
            $v = $variants[$l['variant_id'] ?? ''] ?? null;

            return [
                'variant_id' => $v?->id,
                'category_id' => $v?->product?->category_id,
                'quantity' => (int) $l['quantity'],
                'unit_price' => (string) ($l['unit_price'] ?? $v->ref_price ?? 0),
                'discount' => (string) ($l['discount'] ?? 0),
            ];
        }, $data['lines'] ?? []);
        $customer = filled($data['customer_id'] ?? null) ? Customer::query()->where('ulid', $data['customer_id'])->first() : null;
        $orderId = filled($data['order_id'] ?? null) ? DB::table('orders')->where('ulid', $data['order_id'])->where('tenant_id', app(TenantContext::class)->id())->value('id') : null;

        $result = $engine->evaluate($lines, [
            'channel_id' => filled($data['channel_id'] ?? null) ? SalesChannel::query()->where('ulid', $data['channel_id'])->value('id') : null,
            // A buyer typed on the form is saved with the order: a customer with no earlier uses (0 = not saved yet).
            'customer_id' => $customer->id ?? (! empty($data['new_customer']) ? 0 : null),
            'group_id' => $customer?->customer_group_id,
            'order_id' => $orderId,
            'code' => $data['coupon_code'] ?? null,
            'auto' => ! ($data['promotions_off'] ?? false),
            'at' => isset($data['placed_at']) ? now()->parse($data['placed_at']) : now(),
        ]);
        $out = $result->toArray();
        foreach ($out['applied'] as $i => $a) {
            $gift = $result->applied[$i]['promotion']->gift_variant_id;
            if ($gift !== null && $a['gifts'] !== []) {
                $v = ProductVariant::query()->with('product')->find($gift);
                $out['applied'][$i]['gifts'] = array_map(fn ($g) => $g + ['variant_id' => $v?->ulid, 'sku' => $v?->sku, 'name' => $v ? $v->displayName() : null], $a['gifts']);
            }
        }

        return response()->json(['data' => $out]);
    }

    private function can(string ...$permissions): void
    {
        $ctx = app(TenantContext::class);
        abort_unless(collect($permissions)->contains(fn ($p) => $ctx->can($p)), 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Promotion $current): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(Promotion::TYPES)],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'discount_kind' => ['required_unless:type,gift', 'nullable', Rule::in(Promotion::KINDS)],
            'discount_value' => ['required_unless:type,gift', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'max_discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'tiers' => ['nullable', 'array', 'max:5'],
            'tiers.*.min_subtotal' => ['required', 'numeric', 'min:0'],
            'tiers.*.value' => ['required', 'numeric', 'min:0'],
            'buy_qty' => ['required_if:type,gift', 'nullable', 'integer', 'min:1', 'max:1000'],
            'gift_variant_id' => ['required_if:type,gift', 'nullable', 'string'],
            'gift_qty' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'channel_ids' => ['nullable', 'array'],
            'channel_ids.*' => ['string'],
            'variant_ids' => ['nullable', 'array', 'max:500'],
            'variant_ids.*' => ['string'],
            'category_ids' => ['nullable', 'array', 'max:100'],
            'category_ids.*' => ['string'],
            'customer_group_ids' => ['nullable', 'array'],
            'customer_group_ids.*' => ['string'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in([Promotion::ACTIVE, Promotion::PAUSED])],
        ], ['ends_at.after' => 'Ngày kết thúc phải sau ngày bắt đầu.', 'code.regex' => 'Mã chỉ gồm chữ không dấu, số, gạch ngang.']);

        if (($data['discount_kind'] ?? null) === 'percent' && (float) ($data['discount_value'] ?? 0) > 100) {
            throw ValidationException::withMessages(['discount_value' => 'Giảm theo % tối đa 100.']);
        }
        if (($data['discount_kind'] ?? null) === 'fixed_price' && $data['type'] !== Promotion::PRODUCT) {
            throw ValidationException::withMessages(['discount_kind' => 'Đồng giá chỉ dùng cho khuyến mãi theo sản phẩm.']);
        }
        $data['code'] = filled($data['code'] ?? null) ? strtoupper(trim($data['code'])) : null;
        if ($data['code'] !== null && Promotion::query()->where('code', $data['code'])->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
            throw ValidationException::withMessages(['code' => "Mã {$data['code']} đã dùng cho khuyến mãi khác."]);
        }
        $ids = fn (string $model, ?array $ulids, string $field) => $ulids ? tap($model::query()->whereIn('ulid', $ulids)->pluck('id')->map(fn ($id) => (int) $id)->all(), function ($found) use ($ulids, $field) {
            if (count($found) !== count(array_unique($ulids))) {
                throw ValidationException::withMessages([$field => 'Có mục không tồn tại.']);
            }
        }) : null;
        $data['channel_ids'] = $ids(SalesChannel::class, $data['channel_ids'] ?? null, 'channel_ids');
        $data['variant_ids'] = $ids(ProductVariant::class, $data['variant_ids'] ?? null, 'variant_ids');
        $data['category_ids'] = $ids(Category::class, $data['category_ids'] ?? null, 'category_ids');
        $data['customer_group_ids'] = $ids(CustomerGroup::class, $data['customer_group_ids'] ?? null, 'customer_group_ids');
        $data['gift_variant_id'] = filled($data['gift_variant_id'] ?? null)
            ? (ProductVariant::query()->where('ulid', $data['gift_variant_id'])->value('id') ?? throw ValidationException::withMessages(['gift_variant_id' => 'Sản phẩm tặng không tồn tại.']))
            : null;
        $data['discount_kind'] ??= 'amount';
        $data['discount_value'] ??= 0;
        $data['min_subtotal'] ??= 0;
        if ($data['type'] === Promotion::GIFT) {
            $data['gift_qty'] ??= 1;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Promotion $p, ?object $stats): array
    {
        $ulids = fn (string $model, ?array $ids) => $ids ? $model::query()->whereKey($ids)->pluck('ulid')->all() : [];

        return [
            'id' => $p->ulid,
            'name' => $p->name,
            'type' => $p->type,
            'code' => $p->code,
            'discount_kind' => $p->discount_kind,
            'discount_value' => $p->discount_value,
            'max_discount' => $p->max_discount,
            'min_subtotal' => $p->min_subtotal,
            'tiers' => $p->tiers ?? [],
            'buy_qty' => $p->buy_qty,
            'gift_qty' => $p->gift_qty,
            'gift' => $p->giftVariant ? ['id' => $p->giftVariant->ulid, 'sku' => $p->giftVariant->sku, 'name' => $p->giftVariant->displayName()] : null,
            'channel_ids' => $ulids(SalesChannel::class, $p->channel_ids),
            'variant_ids' => $ulids(ProductVariant::class, $p->variant_ids),
            'variants' => $p->variant_ids ? ProductVariant::query()->with('product')->whereKey($p->variant_ids)->get()->map(fn (ProductVariant $v) => ['id' => $v->ulid, 'sku' => $v->sku, 'name' => $v->displayName()])->values()->all() : [],
            'category_ids' => $ulids(Category::class, $p->category_ids),
            'customer_group_ids' => $ulids(CustomerGroup::class, $p->customer_group_ids),
            'starts_at' => $p->starts_at?->toIso8601String(),
            'ends_at' => $p->ends_at?->toIso8601String(),
            'usage_limit' => $p->usage_limit,
            'per_customer_limit' => $p->per_customer_limit,
            'status' => $p->status,
            'state' => $p->state(),
            'uses' => (int) ($stats->uses ?? 0),
            'amount' => number_format((float) ($stats->amount ?? 0), 2, '.', ''),
        ];
    }
}
