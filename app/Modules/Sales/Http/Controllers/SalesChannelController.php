<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Resources\SalesChannelResource;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesChannelController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SalesChannel::class);

        return SalesChannelResource::collection(SalesChannel::query()->with('warehouse')->withCount('orders')->orderByDesc('is_active')->orderBy('id')->get());
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorize('create', SalesChannel::class);
        $channel = new SalesChannel;
        $this->save($channel, $this->validated($request));
        $audit->record('sales_channel.created', $channel, ['name' => $channel->name]);

        return (new SalesChannelResource($channel->load('warehouse')))->response()->setStatusCode(201);
    }

    public function update(Request $request, SalesChannel $salesChannel, AuditLogger $audit): SalesChannelResource
    {
        $this->authorize('update', $salesChannel);
        $this->save($salesChannel, $this->validated($request, $salesChannel));
        $audit->record('sales_channel.updated', $salesChannel, ['name' => $salesChannel->name]);

        return new SalesChannelResource($salesChannel->load('warehouse'));
    }

    public function destroy(SalesChannel $salesChannel, AuditLogger $audit): JsonResponse
    {
        $this->authorize('delete', $salesChannel);
        if ($salesChannel->orders()->exists()) {
            throw ValidationException::withMessages(['channel' => 'Kênh đã có đơn hàng: hãy tắt kênh thay vì xóa.']);
        }
        $salesChannel->delete();
        $audit->record('sales_channel.deleted', $salesChannel, ['name' => $salesChannel->name]);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SalesChannel $channel = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('sales_channels', 'name')->where('tenant_id', app(TenantContext::class)->id())->ignore($channel?->id)],
            'type' => ['required', Rule::in(SalesChannel::TYPES)],
            'warehouse_id' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function save(SalesChannel $channel, array $data): void
    {
        $channel->fill($data);
        $channel->warehouse_id = empty($data['warehouse_id']) ? null
            : (Warehouse::query()->where('ulid', $data['warehouse_id'])->where('purpose', Warehouse::PURPOSE_SALE)->value('id') ?? throw ValidationException::withMessages(['warehouse_id' => 'Kho không tồn tại.']));
        $channel->save();
    }
}
