<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use App\Modules\Marketplace\Models\MarketplaceSettlement;
use App\Modules\Marketplace\Models\PriceChange;
use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kênh bán (Tất cả kênh, one marketplace's page): what each connected shop sold in the chosen days, against the same
 * number of days before, the orders still open per status, the best sellers and what needs the shop's attention.
 * Revenue = order totals of the orders placed in the days that were not cancelled, returned or left unpaid.
 */
class MarketplaceStatsController extends Controller
{
    private const LOST = [OrderStatus::CANCELLED, OrderStatus::RETURNED, OrderStatus::RETURN_REQUESTED, OrderStatus::FAILED_DELIVERY, OrderStatus::UNPAID];

    /** Open orders, in the order the shop works through them. */
    private const OPEN = [OrderStatus::PENDING_CONFIRMATION, OrderStatus::READY_TO_PACK, OrderStatus::PACKED, OrderStatus::HANDED_OVER, OrderStatus::IN_TRANSIT, OrderStatus::FAILED_DELIVERY];

    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $data = $request->validate(['marketplace' => ['nullable', 'string', 'max:30'], 'days' => ['nullable', 'integer', 'min:1', 'max:92']]);
        $days = (int) ($data['days'] ?? 30);
        $to = ReportRange::now()->endOfDay();
        $from = $to->subDays($days - 1)->startOfDay();
        $prevFrom = $from->subDays($days);
        $today = ReportRange::now()->startOfDay();
        $money = fn ($v) => number_format((float) $v, 2, '.', '');

        $accounts = MarketplaceAccount::query()->where('status', '!=', MarketplaceAccount::REVOKED)->whereNull('archived_at')
            ->when($data['marketplace'] ?? null, fn ($q, $m) => $q->where('marketplace', $m))->orderBy('shop_name')->get();
        $ids = $accounts->pluck('id')->all();
        $orders = fn () => Order::query()->toBase()->whereIn('marketplace_account_id', $ids);
        $lost = "'".implode("','", self::LOST)."'";

        $per = $orders()->whereBetween('placed_at', [$from, $to])->groupBy('marketplace_account_id')->selectRaw("marketplace_account_id,
            COUNT(*) as orders,
            SUM(CASE WHEN status NOT IN ({$lost}) THEN total ELSE 0 END) as revenue,
            SUM(CASE WHEN status NOT IN ({$lost}) THEN 1 ELSE 0 END) as kept,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as returned", [OrderStatus::CANCELLED, OrderStatus::RETURNED, OrderStatus::RETURN_REQUESTED])
            ->get()->keyBy('marketplace_account_id');
        $prev = $orders()->whereBetween('placed_at', [$prevFrom, $from->subSecond()])->groupBy('marketplace_account_id')
            ->selectRaw("marketplace_account_id, COUNT(*) as orders, SUM(CASE WHEN status NOT IN ({$lost}) THEN total ELSE 0 END) as revenue")->get()->keyBy('marketplace_account_id');
        $todayCounts = $orders()->where('placed_at', '>=', $today)->groupBy('marketplace_account_id')->selectRaw('marketplace_account_id, COUNT(*) as n')->pluck('n', 'marketplace_account_id');
        $day = ReportRange::day('placed_at');
        $daily = $orders()->whereBetween('placed_at', [$from, $to])->groupBy('marketplace_account_id', 'd')
            ->selectRaw("marketplace_account_id, {$day} as d, COUNT(*) as n, SUM(CASE WHEN status NOT IN ({$lost}) THEN total ELSE 0 END) as revenue")->get();
        $dates = collect(range(0, $days - 1))->map(fn ($i) => $from->addDays($i)->toDateString());
        $open = $orders()->whereIn('status', self::OPEN)->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status');

        $canFinance = $this->context->can('finance.view');
        $fees = $canFinance ? MarketplaceSettlement::query()->toBase()->whereIn('marketplace_account_id', $ids)
            ->whereIn('order_id', fn ($q) => $q->select('id')->from('orders')->whereIn('marketplace_account_id', $ids)->whereBetween('placed_at', [$from, $to]))
            ->groupBy('marketplace_account_id')->selectRaw('marketplace_account_id, SUM(commission_fee + payment_fee + service_fee + fixed_fee) as fees')->pluck('fees', 'marketplace_account_id') : collect();

        $listings = ListingVariant::query()->toBase()->whereIn('marketplace_account_id', $ids)->groupBy('marketplace_account_id')->selectRaw("marketplace_account_id, COUNT(*) as total,
            SUM(CASE WHEN mapping_status = '".ListingVariant::MAPPED."' THEN 1 ELSE 0 END) as mapped, SUM(CASE WHEN mapping_status = '".ListingVariant::SUGGESTED."' THEN 1 ELSE 0 END) as suggested,
            SUM(CASE WHEN mapping_status = '".ListingVariant::UNMAPPED."' THEN 1 ELSE 0 END) as unmapped")->get()->keyBy('marketplace_account_id');
        $tokens = MarketplaceCredential::query()->whereIn('marketplace_account_id', $ids)->whereNotNull('access_expires_at')->pluck('access_expires_at', 'marketplace_account_id');
        $errors = SyncError::query()->whereIn('marketplace_account_id', $ids)->whereNull('resolved_at')->groupBy('marketplace_account_id')
            ->selectRaw('marketplace_account_id, COUNT(*) as n')->pluck('n', 'marketplace_account_id');
        $prices = PriceChange::query()->whereIn('marketplace_account_id', $ids)->where('status', PriceChange::PENDING)->count();

        $top = Order::query()->toBase()->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.marketplace_account_id', $ids)->whereBetween('orders.placed_at', [$from, $to])->whereNotIn('orders.status', self::LOST)
            ->groupBy('order_items.name', 'order_items.variant_name')
            ->selectRaw('order_items.name, order_items.variant_name, MAX(order_items.seller_sku) as sku, SUM(order_items.quantity) as quantity, SUM(order_items.quantity * order_items.unit_price - order_items.discount) as revenue')
            ->orderByDesc('quantity')->limit(6)->get();

        $rows = $accounts->map(function (MarketplaceAccount $a) use ($per, $prev, $todayCounts, $daily, $dates, $fees, $listings, $tokens, $errors, $money, $canFinance) {
            $p = $per->get($a->id);
            $l = $listings->get($a->id);
            $byDay = $daily->where('marketplace_account_id', $a->id)->keyBy('d');

            return [
                'id' => $a->ulid,
                'marketplace' => $a->marketplace,
                'shop_name' => $a->shop_name,
                'orders_today' => (int) ($todayCounts[$a->id] ?? 0),
                'orders' => (int) ($p->orders ?? 0),
                'kept' => (int) ($p->kept ?? 0),
                'revenue' => $money($p->revenue ?? 0),
                'cancelled' => (int) ($p->cancelled ?? 0),
                'returned' => (int) ($p->returned ?? 0),
                'previous' => ['orders' => (int) ($prev->get($a->id)->orders ?? 0), 'revenue' => $money($prev->get($a->id)->revenue ?? 0)],
                'fees' => $canFinance ? $money($fees[$a->id] ?? 0) : null,
                'daily' => $dates->map(fn ($d) => ['orders' => (int) ($byDay->get($d)->n ?? 0), 'revenue' => $money($byDay->get($d)->revenue ?? 0)])->values(),
                'listings' => ['total' => (int) ($l->total ?? 0), 'mapped' => (int) ($l->mapped ?? 0), 'suggested' => (int) ($l->suggested ?? 0), 'unmapped' => (int) ($l->unmapped ?? 0)],
                'token_expires_at' => isset($tokens[$a->id]) ? Carbon::parse($tokens[$a->id])->toIso8601String() : null,
                'open_errors' => (int) ($errors[$a->id] ?? 0),
            ];
        })->values();

        return response()->json(['data' => [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'dates' => $dates->values(),
            'accounts' => $rows,
            'open' => collect(self::OPEN)->mapWithKeys(fn ($s) => [$s => (int) ($open[$s] ?? 0)]),
            'top' => $top->map(fn ($t) => ['name' => $t->name, 'variant_name' => $t->variant_name, 'sku' => $t->sku, 'quantity' => (int) $t->quantity, 'revenue' => $money($t->revenue)])->values(),
            'pending_prices' => $prices,
            'can_finance' => $canFinance,
        ]]);
    }
}
