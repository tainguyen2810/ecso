<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Controllers\Concerns\ResolvesLines;
use App\Modules\Inventory\Http\Resources\DocumentResource;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Services\InventoryDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdjustmentController extends Controller
{
    use ResolvesLines;

    private const RELATIONS = ['warehouse', 'creator:id,name', 'decider:id,name', 'lines.variant.product'];

    public function __construct(private readonly InventoryDocumentService $documents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', InventoryAdjustment::class);
        $status = $request->validate(['status' => ['nullable', Rule::in([InventoryAdjustment::PENDING, InventoryAdjustment::APPROVED, InventoryAdjustment::REJECTED, InventoryAdjustment::CANCELLED])]])['status'] ?? null;

        return DocumentResource::collection(
            InventoryAdjustment::query()->with(['warehouse', 'creator:id,name', 'decider:id,name'])->withCount('lines')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderByDesc('id')->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('adjust', InventoryAdjustment::class);
        $data = $request->validate([
            'warehouse_id' => ['required', 'string'],
            'reason' => ['required', Rule::in(InventoryAdjustment::REASONS)],
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->lineRules(),
            'lines.*.mode' => ['required', Rule::in(['delta', 'count'])],
            'lines.*.quantity' => ['required', 'integer', 'between:-1000000,1000000'],
        ]);

        foreach ($data['lines'] as $i => $line) {
            if ($line['mode'] === 'count' && $line['quantity'] < 0) {
                abort(422, 'Dòng '.($i + 1).': số lượng kiểm kê không được âm.');
            }
            if ($line['mode'] === 'delta' && $line['quantity'] === 0) {
                abort(422, 'Dòng '.($i + 1).': chênh lệch phải khác 0.');
            }
        }

        $adjustment = $this->documents->createAdjustment($this->findWarehouse($data['warehouse_id']), $this->resolveLines($data['lines']), $data['reason'], $data['note'] ?? null);

        return (new DocumentResource($adjustment->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(InventoryAdjustment $adjustment): DocumentResource
    {
        $this->authorize('view', $adjustment);

        return new DocumentResource($adjustment->load(self::RELATIONS));
    }

    public function approve(Request $request, InventoryAdjustment $adjustment): DocumentResource
    {
        $this->authorize('approve', $adjustment);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        return new DocumentResource($this->documents->approveAdjustment($adjustment, $note)->load(self::RELATIONS));
    }

    public function reject(Request $request, InventoryAdjustment $adjustment): DocumentResource
    {
        $this->authorize('approve', $adjustment);
        $note = $request->validate(['note' => ['required', 'string', 'max:255']])['note'];

        return new DocumentResource($this->documents->rejectAdjustment($adjustment, $note)->load(self::RELATIONS));
    }

    public function cancel(InventoryAdjustment $adjustment): DocumentResource
    {
        $this->authorize('cancel', $adjustment);

        return new DocumentResource($this->documents->cancelAdjustment($adjustment)->load(self::RELATIONS));
    }
}
