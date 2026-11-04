<?php

namespace App\Modules\Cashbook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cashbook\Http\Resources\CashVoucherTypeResource;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Models\CashVoucherType;
use App\Modules\Cashbook\Services\CashVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CashVoucherTypeController extends Controller
{
    public function index(Request $request, CashVoucherService $service): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CashVoucherType::class);
        $service->ensureDefaultTypes();
        $direction = $request->validate(['direction' => ['nullable', Rule::in(CashVoucher::DIRECTIONS)]])['direction'] ?? null;

        return CashVoucherTypeResource::collection(
            CashVoucherType::query()->withCount('vouchers')->when($direction, fn ($q) => $q->where('direction', $direction))
                ->orderByDesc('is_active')->orderBy('name')->get()
        );
    }

    public function store(Request $request, CashVoucherService $service): JsonResponse
    {
        $this->authorize('create', CashVoucherType::class);
        $data = $request->validate([
            'direction' => ['required', Rule::in(CashVoucher::DIRECTIONS)],
            'name' => ['required', 'string', 'max:100'],
            'counts_in_pnl' => ['nullable', 'boolean'],
            'pnl_group' => ['nullable', Rule::in(CashVoucherService::PNL_GROUPS)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        return (new CashVoucherTypeResource($service->createType($data)))->response()->setStatusCode(201);
    }

    public function update(Request $request, CashVoucherType $cashVoucherType, CashVoucherService $service): CashVoucherTypeResource
    {
        $this->authorize('update', CashVoucherType::class);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'counts_in_pnl' => ['sometimes', 'boolean'],
            'pnl_group' => ['sometimes', 'nullable', Rule::in(CashVoucherService::PNL_GROUPS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return new CashVoucherTypeResource($service->updateType($cashVoucherType, $data)->loadCount('vouchers'));
    }
}
