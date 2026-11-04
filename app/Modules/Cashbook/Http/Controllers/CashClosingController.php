<?php

namespace App\Modules\Cashbook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cashbook\Models\CashAccount;
use App\Modules\Cashbook\Models\CashClosing;
use App\Modules\Cashbook\Models\CashCount;
use App\Modules\Cashbook\Services\CashClosingService;
use App\Modules\Cashbook\Support\PeriodLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Counting a fund (kiểm quỹ) and closing the books (khóa sổ). */
class CashClosingController extends Controller
{
    /** Closings, newest first, with the current one (if any). */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', CashAccount::class);
        $names = CashAccount::query()->pluck('name', 'id');
        $closings = CashClosing::query()->with(['creator:id,name', 'reopener:id,name'])->orderByDesc('id')->limit(20)->get();
        $current = PeriodLock::current();

        return response()->json([
            'data' => $closings->map(fn (CashClosing $c) => $this->closing($c, $names->all()))->values(),
            'meta' => ['closed_until' => $current?->closed_until->toDateString(), 'current' => $current?->ulid],
        ]);
    }

    public function close(Request $request, CashClosingService $service): JsonResponse
    {
        $this->authorize('close', CashAccount::class);
        $data = $request->validate(['closed_until' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:255']]);
        $closing = $service->close($data['closed_until'], $data['note'] ?? null);

        return response()->json(['data' => $this->closing($closing->load('creator:id,name'), CashAccount::query()->pluck('name', 'id')->all())], 201);
    }

    public function reopen(Request $request, CashClosing $cashClosing, CashClosingService $service): JsonResponse
    {
        $this->authorize('close', CashAccount::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $closing = $service->reopen($cashClosing, $data['reason']);

        return response()->json(['data' => $this->closing($closing->load(['creator:id,name', 'reopener:id,name']), CashAccount::query()->pluck('name', 'id')->all())]);
    }

    /** The last counts of a fund. */
    public function counts(CashAccount $cashAccount): JsonResponse
    {
        $this->authorize('viewAny', CashAccount::class);
        $counts = CashCount::query()->where('cash_account_id', $cashAccount->id)->with(['creator:id,name', 'voucher:id,ulid,number'])
            ->orderByDesc('counted_at')->orderByDesc('id')->limit(10)->get();

        return response()->json(['data' => $counts->map(fn (CashCount $c) => $this->countRow($c))->values()]);
    }

    public function count(Request $request, CashAccount $cashAccount, CashClosingService $service): JsonResponse
    {
        $this->authorize('close', CashAccount::class);
        $data = $request->validate(['counted_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'], 'note' => ['nullable', 'string', 'max:255']]);
        $count = $service->count($cashAccount, $data['counted_amount'], $data['note'] ?? null);

        return response()->json(['data' => $this->countRow($count->load(['creator:id,name', 'voucher:id,ulid,number']))], 201);
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function closing(CashClosing $c, array $names): array
    {
        return [
            'id' => $c->ulid,
            'closed_until' => $c->closed_until->toDateString(),
            'balances' => collect($c->balances)->map(fn ($v, $id) => ['name' => $names[(int) $id] ?? 'Quỹ đã xóa', 'balance' => (float) $v])->values(),
            'total' => (float) array_sum(array_map('floatval', $c->balances)),
            'note' => $c->note,
            'created_by' => $c->creator?->name,
            'created_at' => $c->created_at?->toIso8601String(),
            'reopened_at' => $c->reopened_at?->toIso8601String(),
            'reopened_by' => $c->reopener?->name,
            'reopen_reason' => $c->reopen_reason,
        ];
    }

    /** @return array<string, mixed> */
    private function countRow(CashCount $c): array
    {
        return [
            'id' => $c->ulid,
            'counted_at' => $c->counted_at->toIso8601String(),
            'system_balance' => (float) $c->system_balance,
            'counted_amount' => (float) $c->counted_amount,
            'difference' => (float) $c->difference,
            'voucher' => $c->voucher ? ['id' => $c->voucher->ulid, 'number' => $c->voucher->number] : null,
            'note' => $c->note,
            'created_by' => $c->creator?->name,
        ];
    }
}
