<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\OrderDraft;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Đơn nháp: save the order form without creating an order (no number, no stock held), reopen it later. */
class OrderDraftController extends Controller
{
    public const MAX_PER_TENANT = 500;

    public function index(): JsonResponse
    {
        $this->authorize('create', Order::class);

        return response()->json(['data' => $this->visible()->with('creator:id,name')->latest('updated_at')->limit(100)->get()->map(fn (OrderDraft $d) => $this->row($d))]);
    }

    public function show(OrderDraft $draft): JsonResponse
    {
        $this->authorize('create', Order::class);
        $this->own($draft);

        return response()->json(['data' => [...$this->row($draft->load('creator:id,name')), 'payload' => $draft->payload]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Order::class);
        if (OrderDraft::query()->count() >= self::MAX_PER_TENANT) {
            return response()->json(['message' => 'Đã có quá nhiều đơn nháp. Hãy xóa bớt nháp cũ.', 'errors' => ['draft' => ['Đã có quá nhiều đơn nháp.']]], 422);
        }
        $draft = new OrderDraft($this->validated($request));
        $draft->forceFill(['created_by' => Auth::id()])->save();

        return response()->json(['data' => $this->row($draft->load('creator:id,name'))], 201);
    }

    public function update(Request $request, OrderDraft $draft): JsonResponse
    {
        $this->authorize('create', Order::class);
        $this->own($draft);
        $draft->fill($this->validated($request))->save();

        return response()->json(['data' => $this->row($draft->load('creator:id,name'))]);
    }

    public function destroy(OrderDraft $draft): JsonResponse
    {
        $this->authorize('create', Order::class);
        $this->own($draft);
        $draft->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'total' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'lines_count' => ['required', 'integer', 'min:0', 'max:200'],
            'payload' => ['required', 'array'],
        ]);
        if (strlen((string) json_encode($data['payload'])) > 65000) {
            abort(response()->json(['message' => 'Nháp quá lớn.', 'errors' => ['payload' => ['Nháp quá lớn.']]], 422));
        }

        return $data;
    }

    /** Drafts of colleagues are seen only with orders.view.all, like their orders. @return Builder<OrderDraft> */
    private function visible(): Builder
    {
        $query = OrderDraft::query();

        return app(TenantContext::class)->can('orders.view.all') ? $query : $query->where('created_by', Auth::id());
    }

    private function own(OrderDraft $draft): void
    {
        abort_unless($this->visible()->whereKey($draft->id)->exists(), 404);
    }

    /** @return array<string, mixed> */
    private function row(OrderDraft $d): array
    {
        return [
            'id' => $d->ulid,
            'title' => $d->title,
            'total' => $d->total,
            'lines_count' => $d->lines_count,
            'created_by' => $d->creator?->name,
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }
}
