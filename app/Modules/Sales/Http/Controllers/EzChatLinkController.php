<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Connectors\EzChat\EzChatGateway;
use App\Modules\Sales\Models\EzChatLink;
use App\Modules\Sales\Models\EzChatLinkOrder;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Services\EzChatStore;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "Nối với EzChat" (Kênh bán): the link to an EzChat workspace, seen and changed by the seller. */
class EzChatLinkController extends Controller
{
    public function show(EzChatStore $store): JsonResponse
    {
        return response()->json(['data' => $this->view($store)]);
    }

    /** "Cho phép" on the link page: back to EzChat with a one-time code. */
    public function grant(Request $request, EzChatStore $store): JsonResponse
    {
        $this->manage();
        $data = $request->validate(['state' => ['required', 'string', 'max:200'], 'redirect' => ['required', 'url', 'max:500']]);

        return response()->json(['url' => $store->grant($data['state'], $data['redirect'])]);
    }

    public function update(Request $request, EzChatStore $store): JsonResponse
    {
        $this->manage();
        $data = $request->validate([
            'order_stage' => ['sometimes', Rule::in(EzChatLink::STAGES)],
            'channel_id' => ['sometimes', 'string'],
        ]);
        $link = EzChatLink::query()->firstOrFail();
        if (isset($data['channel_id'])) {
            $link->sales_channel_id = SalesChannel::query()->where('ulid', $data['channel_id'])->where('is_active', true)->firstOrFail()->id;
        }
        $link->fill($data)->save();

        return response()->json(['data' => $this->view($store)]);
    }

    /** "Ngắt kết nối": EzChat's calls stop working at once (the secret goes with the link). */
    public function destroy(EzChatStore $store): JsonResponse
    {
        $this->manage();
        EzChatLink::query()->delete();

        return response()->json(['data' => $this->view($store)]);
    }

    /** Server to server: EzChat trades the code for the store API address and secret (signed with the shared secret). */
    public function exchange(Request $request, EzChatGateway $gateway, EzChatStore $store): JsonResponse
    {
        if (! $gateway->verifyPush('/'.ltrim($request->path(), '/'), $request->getContent(), $request->headers->all())) {
            return response()->json(['message' => 'Chữ ký không hợp lệ.'], 401);
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:100'], 'workspace' => ['nullable', 'array'], 'workspace.id' => ['nullable', 'string', 'max:64'], 'workspace.name' => ['nullable', 'string', 'max:150']]);

        return response()->json($store->exchange($data['code'], (array) ($data['workspace'] ?? [])));
    }

    private function manage(): void
    {
        abort_unless(app(TenantContext::class)->can('settings.manage'), 403, 'Chỉ chủ cửa hàng hoặc quản lý được nối EzChat.');
    }

    /** @return array<string, mixed> */
    private function view(EzChatStore $store): array
    {
        $link = EzChatLink::query()->with('channel')->first();
        $warehouse = $link ? $store->warehouse($link) : null;

        return [
            'available' => EzChatGateway::configured(),
            'ezchat_url' => rtrim((string) config('marketplaces.ezchat.url'), '/'),
            'linked' => $link !== null,
            'workspace_name' => $link?->workspace_name,
            'order_stage' => $link->order_stage ?? 'pending',
            'channel' => $link?->channel ? ['id' => $link->channel->ulid, 'name' => $link->channel->name] : null,
            'warehouse' => $warehouse?->name,
            'orders' => $link ? EzChatLinkOrder::query()->count() : 0,
            'last_used_at' => $link?->last_used_at?->toIso8601String(),
            'linked_at' => $link?->created_at?->toIso8601String(),
            'can_manage' => app(TenantContext::class)->can('settings.manage'),
        ];
    }
}
