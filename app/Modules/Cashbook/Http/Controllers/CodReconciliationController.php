<?php

namespace App\Modules\Cashbook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Models\CodReconciliation;
use App\Modules\Cashbook\Models\CodReconciliationLine;
use App\Modules\Cashbook\Services\CodReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** COD reconciliations: import a carrier's statement, check it line by line, then book it. */
class CodReconciliationController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', CashVoucher::class);
        $page = CodReconciliation::query()->with(['account:id,ulid,name', 'creator:id,name'])->latest('remitted_at')->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (CodReconciliation $r) => $this->row($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'carriers' => CodReconciliation::CARRIERS,
        ]);
    }

    public function show(CodReconciliation $codReconciliation): JsonResponse
    {
        $this->authorize('view', CashVoucher::class);
        $r = $codReconciliation->load(['account:id,ulid,name', 'creator:id,name', 'feeVoucher:id,ulid,number', 'lines.order:id,ulid,external_order_id']);

        return response()->json(['data' => [
            ...$this->row($r),
            'fee_voucher' => $r->feeVoucher ? ['id' => $r->feeVoucher->ulid, 'number' => $r->feeVoucher->number] : null,
            'lines' => $r->lines->map(fn (CodReconciliationLine $l) => [
                'tracking' => $l->tracking_number,
                'cod' => (float) $l->cod_amount,
                'fee' => (float) $l->fee,
                'status' => $l->status,
                'booked' => (float) $l->booked,
                'note' => $l->note,
                'order' => $l->order ? ['id' => $l->order->ulid, 'number' => $l->order->external_order_id] : null,
            ])->values(),
        ]]);
    }

    /** Reads a statement file (or lines already read) and tells what booking it would do. Writes nothing. */
    public function preview(Request $request, CodReconciliationService $service): JsonResponse
    {
        $this->authorize('create', CashVoucher::class);
        if ($request->hasFile('file')) {
            $request->validate(['file' => ['file', 'max:10240', 'mimes:csv,txt,xlsx']]);
            $file = $request->file('file');
            $lines = $service->read($file->getRealPath(), $file->getClientOriginalExtension() ?: 'csv');
        } else {
            $lines = $request->validate($this->lineRules())['lines'];
        }

        return response()->json(['data' => $service->preview(array_values($lines))]);
    }

    public function store(Request $request, CodReconciliationService $service): JsonResponse
    {
        $this->authorize('create', CashVoucher::class);
        $data = $request->validate([
            'carrier' => ['required', Rule::in(array_keys(CodReconciliation::CARRIERS))],
            'account' => ['nullable', 'string', 'max:40'],
            'remitted_at' => ['nullable', 'date', 'before_or_equal:now'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            ...$this->lineRules(),
        ]);
        $rec = $service->confirm($data);

        return $this->show($rec)->setStatusCode(201);
    }

    /** @return array<string, mixed> */
    private function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.CodReconciliationService::MAX_LINES],
            'lines.*.tracking' => ['required', 'string', 'max:100'],
            'lines.*.cod' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    /** @return array<string, mixed> */
    private function row(CodReconciliation $r): array
    {
        return [
            'id' => $r->ulid,
            'number' => $r->number,
            'carrier' => $r->carrier,
            'carrier_name' => CodReconciliation::CARRIERS[$r->carrier] ?? $r->carrier,
            'file_name' => $r->file_name,
            'account' => $r->account ? ['id' => $r->account->ulid, 'name' => $r->account->name] : null,
            'remitted_at' => $r->remitted_at->toIso8601String(),
            'total_cod' => (float) $r->total_cod,
            'total_fee' => (float) $r->total_fee,
            'total_booked' => (float) $r->total_booked,
            'transferred' => (float) bcsub((string) $r->total_cod, (string) $r->total_fee, 2),
            'lines_count' => $r->lines_count,
            'matched_count' => $r->matched_count,
            'reference' => $r->reference,
            'note' => $r->note,
            'created_by' => $r->creator?->name,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
