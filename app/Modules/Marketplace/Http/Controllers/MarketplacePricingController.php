<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Models\ChannelPriceRule;
use App\Modules\Marketplace\Models\FeeAlert;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceSettlement;
use App\Modules\Marketplace\Notifications\FeeAlertNotification;
use App\Modules\Marketplace\Services\FeeAnalyzer;
use App\Modules\Marketplace\Services\MarketplacePriceBoard;
use App\Modules\Marketplace\Services\MarketplacePricing;
use App\Modules\Marketplace\Services\SettlementImporter;
use App\Shared\Support\SafeRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Round 3: fee tables, the marketplace price board, settlement import and the fee check. */
class MarketplacePricingController extends Controller
{
    private const FEE_RULES = ['nullable', 'numeric', 'min:0', 'max:60'];

    public function __construct(
        private readonly MarketplacePricing $pricing,
        private readonly MarketplacePriceBoard $board,
        private readonly FeeAnalyzer $fees,
    ) {}

    /** Fee table of every marketplace shop (reference figures when none is saved yet) with the actual fees. */
    public function rules(): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $accounts = MarketplaceAccount::query()->where('marketplace', '!=', WooCommerceConnector::MARKETPLACE)
            ->where('status', MarketplaceAccount::CONNECTED)->orderBy('id')->get();
        $categories = Category::query()->pluck('name', 'id');

        return response()->json(['data' => $accounts->map(function (MarketplaceAccount $a) use ($categories) {
            $rules = $this->pricing->rulesOf($a);
            $shop = $rules->get('');
            $actual = $this->fees->actual($a);
            $reference = config("marketplaces.pricing.reference_fees.{$a->marketplace}") ?? ['commission_pct' => 0, 'payment_pct' => 0, 'service_pct' => 0, 'fixed_fee' => 0];

            return [
                'account' => ['id' => $a->ulid, 'shop_name' => $a->shop_name, 'marketplace' => $a->marketplace, 'marketplace_name' => config("marketplaces.catalog.{$a->marketplace}") ?? $a->marketplace],
                'saved' => $shop !== null,
                'shop' => $shop ? $this->ruleData($shop) : $reference + ['rounding' => 100, 'min_margin_pct' => 8, 'auto_apply' => false, 'auto_update_fees' => false, 'suggested_from' => null],
                'categories' => $rules->filter(fn (ChannelPriceRule $r) => $r->category_id !== null)->values()->map(fn (ChannelPriceRule $r) => $this->ruleData($r) + [
                    'category_id' => $r->category?->ulid,
                    'category_name' => $categories[$r->category_id] ?? null,
                    'actual' => $actual['categories'][$r->category_id] ?? null,
                ]),
                'actual' => $actual['shop'],
            ];
        })->values()]);
    }

    /** @return array<string, mixed> */
    private function ruleData(ChannelPriceRule $r): array
    {
        return [
            'commission_pct' => $r->commission_pct !== null ? (float) $r->commission_pct : null,
            'payment_pct' => $r->payment_pct !== null ? (float) $r->payment_pct : null,
            'service_pct' => $r->service_pct !== null ? (float) $r->service_pct : null,
            'fixed_fee' => $r->fixed_fee !== null ? (float) $r->fixed_fee : null,
            'rounding' => (int) $r->rounding,
            'min_margin_pct' => (float) $r->min_margin_pct,
            'auto_apply' => (bool) $r->auto_apply,
            'auto_update_fees' => (bool) $r->auto_update_fees,
            'suggested_from' => $r->suggested_from,
        ];
    }

    /** Saves the fee table of one shop and replaces its category rules. */
    public function saveRules(Request $request, MarketplaceAccount $account): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        abort_if($account->marketplace === WooCommerceConnector::MARKETPLACE, 422, 'Website dùng giá gốc, không có bảng phí sàn.');
        $data = $request->validate([
            'shop.commission_pct' => ['required', 'numeric', 'min:0', 'max:60'],
            'shop.payment_pct' => ['required', 'numeric', 'min:0', 'max:60'],
            'shop.service_pct' => ['required', 'numeric', 'min:0', 'max:60'],
            'shop.fixed_fee' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'shop.rounding' => ['required', 'integer', 'in:1,100,500,1000'],
            'shop.min_margin_pct' => ['required', 'numeric', 'min:0', 'max:500'],
            'shop.auto_apply' => ['boolean'],
            'shop.auto_update_fees' => ['boolean'],
            'categories' => ['array', 'max:100'],
            'categories.*.category_id' => ['required', 'string', 'distinct'],
            'categories.*.commission_pct' => self::FEE_RULES,
            'categories.*.payment_pct' => self::FEE_RULES,
            'categories.*.service_pct' => self::FEE_RULES,
            'categories.*.fixed_fee' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);
        $total = (float) $data['shop']['commission_pct'] + (float) $data['shop']['payment_pct'] + (float) $data['shop']['service_pct'];
        abort_if($total >= MarketplacePricing::MAX_TOTAL_PCT, 422, 'Tổng % phí phải dưới 90%.');

        DB::transaction(function () use ($account, $data) {
            $shop = ChannelPriceRule::query()->firstOrNew(['marketplace_account_id' => $account->id, 'category_id' => null]);
            $before = $shop->exists ? collect(ChannelPriceRule::FEE_FIELDS)->map(fn ($f) => (float) $shop->getAttribute($f))->all() : null;
            $shop->fill($data['shop'] + ['auto_apply' => false, 'auto_update_fees' => false]);
            // Percentages typed by hand are no longer "from settlement data".
            if ($before !== null && $before !== collect(ChannelPriceRule::FEE_FIELDS)->map(fn ($f) => (float) $shop->getAttribute($f))->all()) {
                $shop->suggested_from = null;
            }
            $shop->save();

            $categoryIds = Category::query()->whereIn('ulid', array_column($data['categories'] ?? [], 'category_id'))->pluck('id', 'ulid');
            $keep = [];
            foreach ($data['categories'] ?? [] as $c) {
                $id = $categoryIds[$c['category_id']] ?? abort(422, 'Danh mục không tồn tại.');
                $rule = ChannelPriceRule::query()->updateOrCreate(['marketplace_account_id' => $account->id, 'category_id' => $id], [
                    'commission_pct' => $c['commission_pct'] ?? null,
                    'payment_pct' => $c['payment_pct'] ?? null,
                    'service_pct' => $c['service_pct'] ?? null,
                    'fixed_fee' => $c['fixed_fee'] ?? null,
                ]);
                $keep[] = $rule->id;
            }
            ChannelPriceRule::query()->where('marketplace_account_id', $account->id)->whereNotNull('category_id')->whereNotIn('id', $keep)->delete();
        });

        $applied = ! empty($data['shop']['auto_apply']) ? $this->board->apply(null, $account->id)['applied'] : 0;

        return response()->json(['data' => ['saved' => true, 'applied' => $applied]]);
    }

    /** Writes the actual fees (30 days) into the shop's fee table, or one category rule's commission. */
    public function useActual(Request $request, MarketplaceAccount $account): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate(['category_id' => ['nullable', 'string']]);
        $actual = $this->fees->actual($account);
        $categoryId = isset($data['category_id']) ? Category::query()->where('ulid', $data['category_id'])->value('id') : null;
        $stats = $categoryId ? ($actual['categories'][$categoryId] ?? null) : $actual['shop'];
        abort_unless($stats !== null, 422, 'Chưa có dữ liệu đối soát cho gian hàng này.');

        $rule = ChannelPriceRule::query()->firstOrCreate(
            ['marketplace_account_id' => $account->id, 'category_id' => $categoryId],
            $categoryId ? [] : ['rounding' => 100, 'min_margin_pct' => 8],
        );
        $this->fees->useActual($rule, $stats, (int) config('marketplaces.pricing.check_days'));
        FeeAlert::query()->where('marketplace_account_id', $account->id)->where('type', FeeAlert::FEE_DRIFT)->where('status', FeeAlert::OPEN)
            ->update(['status' => 'applied', 'resolved_at' => now()]);

        return response()->json(['data' => ['saved' => true]]);
    }

    public function board(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $data = $request->validate(['tab' => ['nullable', 'in:'.implode(',', MarketplacePriceBoard::TABS)], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $tab = $data['tab'] ?? 'pending';
        ['accounts' => $accounts, 'rows' => $rows] = $this->board->rows($data['q'] ?? null);

        $counts = [
            'pending' => count(array_filter($rows, fn ($r) => $r['flags']['pending'])),
            'loss' => count(array_filter($rows, fn ($r) => $r['flags']['loss'])),
            'locked' => count(array_filter($rows, fn ($r) => $r['flags']['locked'])),
            'all' => count($rows),
        ];
        $rows = array_values($tab === 'all' ? $rows : array_filter($rows, fn ($r) => $r['flags'][$tab]));
        $page = (int) ($data['page'] ?? 1);
        $perPage = 50;

        return response()->json([
            'data' => array_map(fn ($r) => [
                'variant_id' => $r['variant']->ulid,
                'product_id' => $r['variant']->product?->ulid,
                'name' => $r['variant']->product?->name,
                'variant_name' => $r['variant']->name,
                'sku' => $r['variant']->sku,
                'base' => $r['base'],
                'cost' => $r['cost'],
                'flags' => $r['flags'],
                'cells' => collect($r['cells'])->mapWithKeys(fn ($c, $accountId) => [$accounts->firstWhere('id', $accountId)?->ulid => [
                    'listing_id' => $c['listing']->ulid,
                    'current' => $c['current'],
                    'new' => $c['quote']['price'] ?? null,
                    'raised' => $c['quote']['raised'] ?? false,
                    'new_outcome' => $c['quote'] ? array_intersect_key($c['quote'], array_flip(['fees', 'net', 'profit', 'margin_pct'])) : null,
                    'current_outcome' => $c['current_outcome'],
                    'status' => $c['status'],
                    'locked' => $c['locked'],
                    'below_margin' => $c['below_margin'],
                    'min_margin_pct' => $c['fee']['min_margin_pct'] ?? null,
                ]]),
            ], array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'accounts' => $accounts->map(fn (MarketplaceAccount $a) => ['id' => $a->ulid, 'shop_name' => $a->shop_name, 'marketplace' => $a->marketplace])->values(),
            'counts' => $counts,
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($rows) / $perPage)), 'total' => count($rows)],
            'rules_updated_at' => ChannelPriceRule::query()->max('updated_at'),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate(['listing_ids' => ['nullable', 'array', 'max:5000'], 'listing_ids.*' => ['string'], 'all' => ['boolean']]);
        abort_if(empty($data['all']) && empty($data['listing_ids']), 422, 'Chọn giá cần áp dụng.');

        return response()->json(['data' => $this->board->apply(! empty($data['all']) ? null : $data['listing_ids'])]);
    }

    public function lock(Request $request): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate(['listing_ids' => ['required', 'array', 'max:5000'], 'listing_ids.*' => ['string'], 'locked' => ['required', 'boolean']]);

        return response()->json(['data' => ['updated' => $this->board->lock($data['listing_ids'], $data['locked'])]]);
    }

    /** Price file of one shop: the SKUs whose new price waits to be uploaded in the seller centre. */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $account = MarketplaceAccount::query()->where('ulid', (string) $request->query('account'))->firstOrFail();
        $rows = $this->board->exportRows($account);
        $filename = 'gia-'.$account->marketplace.'-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($rows) {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $writer->addRow(SafeRow::fromValues(['Mã sản phẩm sàn', 'Mã phân loại sàn', 'SKU người bán', 'Tên sản phẩm', 'Giá hiện tại', 'Giá mới']));
            foreach ($rows as $r) {
                $writer->addRow(SafeRow::fromValues([$r['external_product_id'], $r['external_sku_id'], $r['seller_sku'], $r['name'], $r['current'], $r['new']]));
            }
            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Fee check page: actual fees per shop and category, open alerts, last imports. */
    public function fees(): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $accounts = MarketplaceAccount::query()->where('marketplace', '!=', WooCommerceConnector::MARKETPLACE)
            ->where('status', MarketplaceAccount::CONNECTED)->orderBy('id')->get();
        $categories = Category::query()->pluck('name', 'id');
        $imports = MarketplaceSettlement::query()->selectRaw('marketplace_account_id, MAX(created_at) as last_at, COUNT(*) as orders')
            ->groupBy('marketplace_account_id')->get()->keyBy('marketplace_account_id');

        return response()->json([
            'data' => $accounts->map(function (MarketplaceAccount $a) use ($categories, $imports): array {
                $actual = $this->fees->actual($a);
                $fee = $this->pricing->effective($this->pricing->rulesOf($a), null);

                return [
                    'account' => ['id' => $a->ulid, 'shop_name' => $a->shop_name, 'marketplace' => $a->marketplace],
                    'actual' => $actual['shop'],
                    'table' => $fee,
                    'categories' => collect($actual['categories'])->map(fn ($s, $id) => $s + ['category_name' => $categories[$id] ?? '—'])->sortByDesc('order_amount')->take(8)->values(),
                    'last_import_at' => $imports->get($a->id)?->getAttribute('last_at'),
                    'settlements' => (int) $imports->get($a->id)?->getAttribute('orders'),
                ];
            })->values(),
            'alerts' => $this->fees->openAlerts()->map(fn (FeeAlert $al) => [
                'id' => $al->ulid,
                'type' => $al->type,
                'account' => $al->account ? ['id' => $al->account->ulid, 'shop_name' => $al->account->shop_name, 'marketplace' => $al->account->marketplace] : null,
                'text' => FeeAlertNotification::describe($al),
                'data' => $al->data,
                'created_at' => $al->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function import(Request $request, SettlementImporter $importer): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $request->validate(['account' => ['required', 'string'], 'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,csv,txt']]);
        $account = MarketplaceAccount::query()->where('ulid', $request->string('account'))->firstOrFail();
        $file = $request->file('file');
        $read = $importer->read($file->getRealPath(), $file->getClientOriginalExtension());
        if ($read['errors'] !== []) {
            return response()->json(['message' => $read['errors'][0], 'errors' => ['file' => $read['errors']], 'columns' => array_keys($read['columns'])], 422);
        }
        $result = $importer->import($account, $read['rows']);
        // Check now so the page shows fresh alerts without waiting for tomorrow morning.
        $this->fees->check($account);

        return response()->json(['data' => $result + ['columns' => $read['columns']]]);
    }

    public function template(): StreamedResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);

        return response()->streamDownload(function () {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $writer->addRow(SafeRow::fromValues(['Mã đơn hàng', 'Ngày đối soát', 'Giá trị hàng', 'Phí hoa hồng', 'Phí thanh toán', 'Phí dịch vụ', 'Phí cố định mỗi đơn', 'Phí vận chuyển sàn trừ', 'Thực nhận']));
            $writer->addRow(SafeRow::fromValues(['2511040001ABC', '04/11/2026', 570600, 51354, 27959, 34236, 3000, 0, 454051]));
            $writer->close();
        }, 'mau-doi-soat-san.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function applyAlert(FeeAlert $alert): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);

        return response()->json(['data' => ['id' => $alert->ulid, 'status' => $this->fees->applyAlert($alert)->status]]);
    }

    public function dismissAlert(FeeAlert $alert): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);

        return response()->json(['data' => ['id' => $alert->ulid, 'status' => $this->fees->dismiss($alert)->status]]);
    }
}
