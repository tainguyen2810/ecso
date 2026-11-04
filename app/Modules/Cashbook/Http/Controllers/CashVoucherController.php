<?php

namespace App\Modules\Cashbook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cashbook\Http\Resources\VoucherRowResource;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Services\CashVoucherService;
use App\Modules\Cashbook\Services\VoucherLedger;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Http\Resources\PaymentResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerDebt;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CashVoucherController extends Controller
{
    public function index(Request $request, VoucherLedger $ledger): JsonResponse
    {
        $this->authorize('viewAny', CashVoucher::class);
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(CashVoucher::DIRECTIONS)],
            'status' => ['nullable', Rule::in(['active', 'voided'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'method' => ['nullable', Rule::in(CashVoucher::METHODS)],
            'account' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', 'string', 'max:40'],
            'party_kind' => ['nullable', Rule::in(CashVoucher::PARTY_KINDS)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        ['page' => $page, 'totals' => $totals] = $ledger->search($filters, (int) ($filters['per_page'] ?? 20));

        return response()->json([
            'data' => VoucherRowResource::collection($page->getCollection())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'totals' => $totals,
        ]);
    }

    public function show(string $source, string $id, VoucherLedger $ledger): VoucherRowResource
    {
        $this->authorize('view', CashVoucher::class);
        abort_unless(in_array($source, VoucherLedger::SOURCES, true), 404);

        return new VoucherRowResource($ledger->find($source, $id) ?? abort(404));
    }

    public function store(Request $request, CashVoucherService $service, VoucherLedger $ledger): JsonResponse
    {
        $this->authorize('create', CashVoucher::class);
        $data = $request->validate([
            'direction' => ['required', Rule::in(CashVoucher::DIRECTIONS)],
            'type' => ['required', 'string', 'max:40'],
            'party_kind' => ['required', Rule::in(CashVoucher::PARTY_KINDS)],
            'party_id' => ['nullable', 'string', 'max:40'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'method' => ['required', Rule::in(CashVoucher::METHODS)],
            'account' => ['nullable', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'counts_in_pnl' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        $voucher = $service->record($data);

        return (new VoucherRowResource($ledger->find('voucher', $voucher->ulid)))->response()->setStatusCode(201);
    }

    public function void(Request $request, CashVoucher $cashVoucher, CashVoucherService $service, VoucherLedger $ledger): VoucherRowResource
    {
        $this->authorize('void', CashVoucher::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $service->void($cashVoucher, $data['reason']);

        return new VoucherRowResource($ledger->find('voucher', $cashVoucher->ulid));
    }

    /** Members of the business, to pick as the staff a voucher is paid to or received from. */
    public function staff(TenantContext $context): JsonResponse
    {
        $this->authorize('create', CashVoucher::class);
        $users = User::query()
            ->whereIn('id', DB::table('tenant_users')->where('tenant_id', $context->id())->where('status', 'active')->select('user_id'))
            ->orderBy('name')->get(['ulid', 'name']);

        return response()->json(['data' => $users->map(fn (User $u) => ['id' => $u->ulid, 'name' => $u->name])]);
    }

    /** Debt still owed by a customer, order by order (oldest first), before taking a debt receipt. */
    public function customerDebt(Customer $customer, CashVoucherService $service, CustomerDebt $debt): JsonResponse
    {
        $this->authorize('pay', Order::class);
        $orders = $service->unpaidOrders($customer);

        return response()->json(['data' => [
            'debt' => (float) $debt->forCustomer($customer),
            'other' => max(0, (float) $debt->entriesBalance($customer)),
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->ulid,
                'number' => $o->external_order_id,
                'placed_at' => $o->placed_at?->toIso8601String(),
                'due' => (float) CashVoucherService::due($o),
            ])->values(),
        ]]);
    }

    /** One receipt from a customer, split over their unpaid orders oldest first. */
    public function collectCustomerDebt(Request $request, Customer $customer, CashVoucherService $service): JsonResponse
    {
        $this->authorize('pay', Order::class);
        $data = $request->validate([
            'method' => ['required', Rule::in(CashVoucher::METHODS)],
            'account' => ['nullable', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        $result = $service->collectCustomerDebt($customer, $data);

        return PaymentResource::collection($result['payments']->each->load('creator'))
            ->additional(['voucher' => $result['voucher'] ? ['id' => $result['voucher']->ulid, 'number' => $result['voucher']->number, 'amount' => (string) $result['voucher']->amount] : null])
            ->response()->setStatusCode(201);
    }
}
