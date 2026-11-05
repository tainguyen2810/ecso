<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cashbook\Models\CashVoucherType;
use App\Modules\Cashbook\Services\CashVoucherService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Resources\CashShiftResource;
use App\Modules\Sales\Models\CashShift;
use App\Modules\Sales\Models\CashShiftMovement;
use App\Modules\Sales\Services\CashShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashShiftController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CashShift::class);
        $data = $request->validate([
            'status' => ['nullable', Rule::in([CashShift::OPEN, CashShift::CLOSED])],
            'cashier' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = CashShift::query()->with(['user', 'warehouse', 'closer'])->latest('opened_at')->latest('id');
        if (! $request->user()->can('viewAll', CashShift::class)) {
            $query->where('user_id', $request->user()->id);
        } elseif ($data['cashier'] ?? null) {
            $query->whereIn('user_id', User::query()->where('ulid', $data['cashier'])->select('id'));
        }
        $query->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('opened_at', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('opened_at', '<', now()->parse($d)->addDay()->startOfDay()));

        return CashShiftResource::collection($query->paginate($data['per_page'] ?? 20)->withQueryString())
            ->additional(['meta' => ['can_view_all' => $request->user()->can('viewAll', CashShift::class)]]);
    }

    /**
     * Voucher types a cashier can book drawer money under (active ones; receipt for money in, payment for money out),
     * so a cashier without finance access can still record an expense paid from the drawer.
     */
    public function voucherTypes(CashVoucherService $vouchers): JsonResponse
    {
        $this->authorize('create', CashShift::class);
        $vouchers->ensureDefaultTypes();
        $types = CashVoucherType::query()->where('is_active', true)->orderBy('name')->get(['ulid', 'direction', 'name']);

        return response()->json(['data' => $types->map(fn ($t) => ['id' => $t->ulid, 'direction' => $t->direction, 'name' => $t->name])->values()]);
    }

    /** The caller's open shift (null when none), and the float the next shift should start with. */
    public function current(Request $request, CashShiftService $shifts): JsonResponse
    {
        $this->authorize('create', CashShift::class);
        $request->validate(['warehouse' => ['nullable', 'string']]);
        $shift = $shifts->current($request->user());
        $warehouse = $request->query('warehouse') ? Warehouse::query()->where('ulid', $request->query('warehouse'))->first() : null;

        return response()->json([
            'data' => $shift ? (new CashShiftResource($shift->load(['user', 'warehouse', 'movements.creator', 'movements.voucher'])))->resolve($request) : null,
            'meta' => ['suggested_opening' => $shifts->suggestedOpening($warehouse), 'denominations' => CashShiftService::DENOMINATIONS],
        ]);
    }

    public function show(CashShift $shift): CashShiftResource
    {
        $this->authorize('view', $shift);

        return new CashShiftResource($shift->load(['user', 'warehouse', 'closer', 'movements.creator', 'movements.voucher']));
    }

    public function store(Request $request, CashShiftService $shifts): JsonResponse
    {
        $this->authorize('create', CashShift::class);
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'string'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'opening_note' => ['nullable', 'string', 'max:500'],
        ]);
        $warehouse = null;
        if ($data['warehouse_id'] ?? null) {
            $warehouse = Warehouse::query()->where('ulid', $data['warehouse_id'])->first()
                ?? throw ValidationException::withMessages(['warehouse_id' => 'Kho không tồn tại.']);
        }

        $shift = $shifts->open($request->user(), $warehouse, $data);

        return (new CashShiftResource($shift->load(['user', 'warehouse', 'movements'])))->response()->setStatusCode(201);
    }

    public function move(Request $request, CashShift $shift, CashShiftService $shifts): JsonResponse
    {
        $this->authorize('update', $shift);
        $data = $request->validate([
            'kind' => ['required', Rule::in([CashShiftMovement::IN, CashShiftMovement::OUT])],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'reason' => ['required', 'string', 'max:255'],
            'voucher_type' => ['nullable', 'string', 'max:40'],
        ]);
        $shifts->move($shift, $data);

        return (new CashShiftResource($shift->refresh()->load(['user', 'warehouse', 'movements.creator', 'movements.voucher'])))->response()->setStatusCode(201);
    }

    public function close(Request $request, CashShift $shift, CashShiftService $shifts): CashShiftResource
    {
        $this->authorize('update', $shift);
        $data = $request->validate([
            'counted_cash' => ['required_without:denominations', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],
            'handed_over' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'closing_note' => ['nullable', 'string', 'max:500'],
        ]);

        return new CashShiftResource($shifts->close($shift, $data)->load(['user', 'warehouse', 'closer', 'movements.creator', 'movements.voucher']));
    }

    /** Opens a closed shift again to correct it (shift supervisors only, reason required, logged). */
    public function reopen(Request $request, CashShift $shift, CashShiftService $shifts): CashShiftResource
    {
        $this->authorize('viewAll', CashShift::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new CashShiftResource($shifts->reopen($shift, $data['reason'])->load(['user', 'warehouse', 'closer', 'movements.creator', 'movements.voucher']));
    }
}
