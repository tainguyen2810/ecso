<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Support\DataAccess;
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
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([InventoryAdjustment::DRAFT, InventoryAdjustment::PENDING, InventoryAdjustment::APPROVED, InventoryAdjustment::REJECTED, InventoryAdjustment::CANCELLED])],
            'reason' => ['nullable', Rule::in(InventoryAdjustment::REASONS)],
            'warehouse' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return DocumentResource::collection(
            InventoryAdjustment::query()->with(['warehouse', 'creator:id,name', 'decider:id,name'])->withCount('lines')
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                // Drafts are someone's counting in progress: listed only where asked for (the stocktake list).
                ->when(! isset($filters['status']) && ! isset($filters['reason']), fn ($q) => $q->where('status', '!=', InventoryAdjustment::DRAFT))
                ->when($filters['reason'] ?? null, fn ($q, $r) => $q->where('reason', $r))
                ->when($filters['warehouse'] ?? null, fn ($q, $w) => $q->whereHas('warehouse', fn ($h) => $h->where('ulid', $w)))
                ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('note', 'like', "%{$t}%")))
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
            'draft' => ['boolean'],
            ...$this->adjustmentLineRules(),
        ]);
        $this->assertLines($data['lines']);

        $adjustment = $this->documents->createAdjustment($this->findWarehouse($data['warehouse_id']), $this->resolveLines($data['lines']), $data['reason'], $data['note'] ?? null, $request->boolean('draft'));

        return (new DocumentResource($adjustment->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    /** Saves the counts of a draft stocktake again. */
    public function update(Request $request, InventoryAdjustment $adjustment): DocumentResource
    {
        $this->authorize('cancel', $adjustment); // its creator, or an approver
        app(DataAccess::class)->assertWarehouse($adjustment->warehouse_id);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000'], ...$this->adjustmentLineRules()]);
        $this->assertLines($data['lines']);

        return new DocumentResource($this->documents->updateDraft($adjustment, $this->resolveLines($data['lines']), $data['note'] ?? null)->load(self::RELATIONS));
    }

    /**
     * "Cân bằng kho": the draft is finished. Approvers apply it at once (stock becomes the count); others send it for
     * approval.
     */
    public function balance(Request $request, InventoryAdjustment $adjustment): DocumentResource
    {
        $this->authorize('cancel', $adjustment);
        app(DataAccess::class)->assertWarehouse($adjustment->warehouse_id);
        $adjustment = $this->documents->submitDraft($adjustment);
        if ($request->user()->can('approve', $adjustment)) {
            $adjustment = $this->documents->approveAdjustment($adjustment);
        }

        return new DocumentResource($adjustment->load(self::RELATIONS));
    }

    /** @return array<string, array<int, mixed>> */
    private function adjustmentLineRules(): array
    {
        return [
            ...$this->lineRules(),
            'lines.*.mode' => ['required', Rule::in(['delta', 'count'])],
            'lines.*.quantity' => ['required', 'integer', 'between:-1000000,1000000'],
            // Count lines: the system quantity shown when the SKU was counted (stocktake screen).
            'lines.*.system_qty' => ['nullable', 'integer', 'between:-1000000,1000000'],
            'lines.*.reason' => ['nullable', Rule::in(InventoryAdjustment::REASONS)],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @param  array<int, array{mode: string, quantity: int}>  $lines */
    private function assertLines(array $lines): void
    {
        foreach ($lines as $i => $line) {
            if ($line['mode'] === 'count' && $line['quantity'] < 0) {
                abort(422, 'Dòng '.($i + 1).': số lượng kiểm kê không được âm.');
            }
            if ($line['mode'] === 'delta' && $line['quantity'] === 0) {
                abort(422, 'Dòng '.($i + 1).': chênh lệch phải khác 0.');
            }
        }
    }

    public function show(InventoryAdjustment $adjustment): DocumentResource
    {
        $this->authorize('view', $adjustment);

        return new DocumentResource($adjustment->load(self::RELATIONS));
    }

    public function approve(Request $request, InventoryAdjustment $adjustment): DocumentResource
    {
        $this->authorize('approve', $adjustment);
        app(DataAccess::class)->assertWarehouse($adjustment->warehouse_id);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        return new DocumentResource($this->documents->approveAdjustment($adjustment, $note)->load(self::RELATIONS));
    }

    public function reject(Request $request, InventoryAdjustment $adjustment): DocumentResource
    {
        $this->authorize('approve', $adjustment);
        app(DataAccess::class)->assertWarehouse($adjustment->warehouse_id);
        $note = $request->validate(['note' => ['required', 'string', 'max:255']])['note'];

        return new DocumentResource($this->documents->rejectAdjustment($adjustment, $note)->load(self::RELATIONS));
    }

    public function cancel(InventoryAdjustment $adjustment): DocumentResource
    {
        $this->authorize('cancel', $adjustment);
        app(DataAccess::class)->assertWarehouse($adjustment->warehouse_id);

        return new DocumentResource($this->documents->cancelAdjustment($adjustment)->load(self::RELATIONS));
    }
}
