<?php

namespace App\Modules\Cashbook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cashbook\Http\Resources\CashAccountResource;
use App\Modules\Cashbook\Http\Resources\VoucherRowResource;
use App\Modules\Cashbook\Models\CashAccount;
use App\Modules\Cashbook\Models\CashTransfer;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Services\CashAccountService;
use App\Modules\Cashbook\Services\CashFlows;
use App\Modules\Cashbook\Services\VoucherLedger;
use App\Modules\Cashbook\Support\PeriodLock;
use App\Shared\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Funds (quỹ) with their balances, and transfers between them. */
class CashAccountController extends Controller
{
    /** Funds with their balances now, or at the end of the day `at` (in the business's time zone). */
    public function index(Request $request, CashAccountService $service, CashFlows $flows): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CashAccount::class);
        $at = $request->validate(['at' => ['nullable', 'date_format:Y-m-d']])['at'] ?? null;
        $accounts = $service->all();
        CashAccountResource::$balances = $flows->balances($at ? ReportRange::date($at)->endOfDay() : null);

        return CashAccountResource::collection($accounts)->additional(['meta' => [
            'total' => $request->user()?->can('balances', CashAccount::class) ? (float) collect(CashAccountResource::$balances)->only($accounts->where('is_active', true)->pluck('id')->all())->sum() : null,
            'closed_until' => PeriodLock::current()?->closed_until->toDateString(),
        ]]);
    }

    public function store(Request $request, CashAccountService $service, CashFlows $flows): JsonResponse
    {
        $this->authorize('manage', CashAccount::class);
        $account = $service->create($request->validate($this->rules(true)));
        CashAccountResource::$balances = $flows->balances();

        return (new CashAccountResource($account))->response()->setStatusCode(201);
    }

    public function update(Request $request, CashAccount $cashAccount, CashAccountService $service, CashFlows $flows): CashAccountResource
    {
        $this->authorize('manage', CashAccount::class);
        $account = $service->update($cashAccount, $request->validate($this->rules(false)));
        CashAccountResource::$balances = $flows->balances();

        return new CashAccountResource($account);
    }

    public function transfer(Request $request, CashAccountService $service, VoucherLedger $ledger): JsonResponse
    {
        $this->authorize('manage', CashAccount::class);
        $data = $request->validate([
            'from' => ['required', 'string', 'max:40'],
            'to' => ['required', 'string', 'max:40', 'different:from'],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'transferred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $transfer = $service->transfer($data);

        return (new VoucherRowResource($ledger->find('transfer', $transfer->ulid)))->response()->setStatusCode(201);
    }

    public function voidTransfer(Request $request, CashTransfer $cashTransfer, CashAccountService $service, VoucherLedger $ledger): VoucherRowResource
    {
        $this->authorize('manage', CashAccount::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $service->voidTransfer($cashTransfer, $data['reason']);

        return new VoucherRowResource($ledger->find('transfer', $cashTransfer->ulid));
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $sometimes = $creating ? 'required' : 'sometimes';

        return [
            ...($creating ? ['kind' => ['required', Rule::in(CashAccount::KINDS)]] : []),
            'name' => [$sometimes, 'string', 'max:100'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'account_number' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9A-Za-z]{4,30}$/'],
            'holder' => ['sometimes', 'nullable', 'string', 'max:100'],
            'methods' => ['sometimes', 'array'],
            'methods.*' => [Rule::in(CashVoucher::METHODS)],
            'opening_balance' => ['sometimes', 'nullable', 'numeric', 'min:-9999999999', 'max:9999999999'],
            'opening_at' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...($creating ? [] : ['is_active' => ['sometimes', 'boolean']]),
        ];
    }
}
