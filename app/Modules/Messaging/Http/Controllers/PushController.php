<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\PushSubscription;
use App\Modules\Messaging\Push\WebPush;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** "Báo đơn mới trên máy này" (Đợt 8): the browser subscribes to Web Push for this store. */
class PushController extends Controller
{
    public function key(Request $request, WebPush $push): JsonResponse
    {
        $endpoint = (string) $request->query('endpoint', '');

        return response()->json(['data' => [
            'public_key' => $push->publicKey(),
            'subscribed' => $endpoint !== '' && PushSubscription::query()->where('endpoint_hash', PushSubscription::hashOf($endpoint))->exists(),
        ]]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(app(TenantContext::class)->can('orders.view'), 403, 'Bạn không xem được đơn hàng nên không nhận báo đơn mới.');
        $data = $request->validate(['endpoint' => ['required', 'url', 'starts_with:https://', 'max:1000'], 'device' => ['nullable', 'string', 'max:300']]);
        $sub = PushSubscription::query()->firstOrNew(['endpoint_hash' => PushSubscription::hashOf($data['endpoint'])]);
        $sub->fill(['user_id' => Auth::id(), 'endpoint' => $data['endpoint'], 'device' => Str::limit((string) ($data['device'] ?? ''), 120, '')])->save();

        return response()->json(['data' => ['subscribed' => true]]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        PushSubscription::query()->where('endpoint_hash', PushSubscription::hashOf($data['endpoint']))->delete();

        return response()->json(['data' => ['subscribed' => false]]);
    }

    /** "Gửi thử": a test notification to this browser. */
    public function test(Request $request, WebPush $push): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $sub = PushSubscription::query()->where('endpoint_hash', PushSubscription::hashOf($data['endpoint']))->firstOrFail();
        $sub->forceFill(['last_title' => 'Thử báo đơn mới', 'last_body' => 'Máy này sẽ nhận báo khi có đơn mới.', 'last_url' => '/orders', 'last_sent_at' => now()])->save();
        $push->wake($sub->endpoint, 300);

        return response()->json(['data' => ['sent' => true]]);
    }

    /**
     * The service worker, woken by a push, reads what to show. No session: the endpoint (secret, known only to the
     * browser and us) is the key.
     */
    public function last(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint', '');
        $sub = $endpoint === '' ? null : PushSubscription::withoutGlobalScopes()->where('endpoint_hash', PushSubscription::hashOf($endpoint))->latest('last_sent_at')->first();

        return response()->json(['data' => $sub?->last_title ? ['title' => $sub->last_title, 'body' => $sub->last_body, 'url' => $sub->last_url] : null]);
    }
}
