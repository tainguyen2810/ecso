<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Domain\AllocationCalculator;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\AllocationBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Sync Center → Phân bổ tồn: how much of each SKU's sellable stock every shop publishes. */
class AllocationController extends Controller
{
    public function __construct(private readonly AllocationBoard $board) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewSync', MarketplaceAccount::class);
        $search = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']])['q'] ?? null;

        return response()->json(['data' => $this->board->overview($search ?: null) + [
            'can_configure' => $request->user()->can('configureSync', MarketplaceAccount::class),
        ]]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorize('configureSync', MarketplaceAccount::class);
        [$account, $variant, $rule] = $this->input($request);

        return response()->json(['data' => $this->board->preview($account, $variant, $rule)]);
    }

    public function save(Request $request): JsonResponse
    {
        $this->authorize('configureSync', MarketplaceAccount::class);
        [$account, $variant, $rule] = $this->input($request);

        return response()->json(['data' => ['queued' => $this->board->save($account, $variant, $rule)]]);
    }

    /**
     * account + optional variant (ulids) and the rule; `reset: true` removes the rule (SKU → shop default,
     * shop → publish everything).
     *
     * @return array{0: MarketplaceAccount, 1: ProductVariant|null, 2: array{mode: string, value: int|null, buffer_qty: int, max_qty: int|null}|null}
     */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'account' => ['required', 'string', 'max:26'],
            'variant' => ['sometimes', 'nullable', 'string', 'max:26'],
            'reset' => ['sometimes', 'boolean'],
            'mode' => ['required_unless:reset,true', Rule::in(AllocationCalculator::MODES)],
            'value' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'buffer_qty' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'max_qty' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $account = MarketplaceAccount::query()->where('ulid', $data['account'])->firstOrFail();
        $variant = isset($data['variant']) ? ProductVariant::query()->where('ulid', $data['variant'])->firstOrFail() : null;
        if ($request->boolean('reset')) {
            return [$account, $variant, null];
        }

        $mode = $data['mode'];
        $value = $data['value'] ?? null;
        if ($mode === AllocationCalculator::MODE_PERCENT && ($value === null || $value > 100)) {
            throw ValidationException::withMessages(['value' => 'Tỷ lệ phải từ 0 đến 100%.']);
        }
        if ($mode === AllocationCalculator::MODE_FIXED && $value === null) {
            throw ValidationException::withMessages(['value' => 'Nhập số lượng công bố.']);
        }

        return [$account, $variant, [
            'mode' => $mode,
            'value' => in_array($mode, [AllocationCalculator::MODE_PERCENT, AllocationCalculator::MODE_FIXED], true) ? (int) $value : null,
            'buffer_qty' => $mode === AllocationCalculator::MODE_NONE ? 0 : (int) ($data['buffer_qty'] ?? 0),
            'max_qty' => $mode === AllocationCalculator::MODE_NONE || ! isset($data['max_qty']) ? null : (int) $data['max_qty'],
        ]];
    }
}
