<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Support\DataAccess;
use App\Modules\Inventory\Http\Controllers\Concerns\ResolvesLines;
use App\Modules\Inventory\Http\Resources\DocumentResource;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\InventoryDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    use ResolvesLines;

    private const RELATIONS = ['fromWarehouse', 'toWarehouse', 'creator:id,name', 'lines.variant.product'];

    public function __construct(private readonly InventoryDocumentService $documents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockTransfer::class);
        $status = $request->validate(['status' => ['nullable', Rule::in([StockTransfer::DRAFT, StockTransfer::IN_TRANSIT, StockTransfer::RECEIVED, StockTransfer::CANCELLED])]])['status'] ?? null;

        return DocumentResource::collection(
            StockTransfer::query()->with(['fromWarehouse', 'toWarehouse', 'creator:id,name'])->withCount('lines')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderByDesc('id')->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('transfer', StockTransfer::class);
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'string'],
            'to_warehouse_id' => ['required', 'string', 'different:from_warehouse_id'],
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->lineRules(),
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $transfer = $this->documents->createTransfer(
            $this->findWarehouse($data['from_warehouse_id'], 'from_warehouse_id'),
            $this->findWarehouse($data['to_warehouse_id'], 'to_warehouse_id', assigned: false),
            $this->resolveLines($data['lines']),
            $data['note'] ?? null,
        );

        return (new DocumentResource($transfer->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(StockTransfer $transfer): DocumentResource
    {
        $this->authorize('view', $transfer);

        return new DocumentResource($transfer->load(self::RELATIONS));
    }

    public function dispatchTransfer(StockTransfer $transfer): DocumentResource
    {
        $this->authorize('transfer', $transfer);
        app(DataAccess::class)->assertWarehouse($transfer->from_warehouse_id, 'from_warehouse_id');

        return new DocumentResource($this->documents->dispatchTransfer($transfer)->load(self::RELATIONS));
    }

    /** Optional lines[]: {line_id, received_qty} for what actually arrived; lines left out arrived in full. */
    public function receive(Request $request, StockTransfer $transfer): DocumentResource
    {
        $this->authorize('transfer', $transfer);
        app(DataAccess::class)->assertWarehouse($transfer->to_warehouse_id, 'to_warehouse_id');
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['sometimes', 'array', 'max:500'],
            'lines.*.line_id' => ['required', 'integer', 'distinct'],
            'lines.*.received_qty' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $received = collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['line_id'] => (int) $l['received_qty']])->all();
        $unknown = array_diff(array_keys($received), $transfer->lines()->pluck('id')->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['lines' => 'Dòng không thuộc phiếu chuyển này.']);
        }

        return new DocumentResource($this->documents->receiveTransfer($transfer, $received, $data['note'] ?? null)->load(self::RELATIONS));
    }

    public function cancel(StockTransfer $transfer): DocumentResource
    {
        $this->authorize('transfer', $transfer);
        app(DataAccess::class)->assertWarehouse($transfer->from_warehouse_id, 'from_warehouse_id');

        return new DocumentResource($this->documents->cancelTransfer($transfer)->load(self::RELATIONS));
    }
}
