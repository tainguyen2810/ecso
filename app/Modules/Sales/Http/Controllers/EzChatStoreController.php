<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\EzChatLink;
use App\Modules\Sales\Services\EzChatStore;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The store API EzChat calls for a linked workspace (Đợt 7). Every request is signed like EzChat signs a shop's own
 * API: X-EzChat-Timestamp and X-EzChat-Signature = "sha256=" + HMAC-SHA256(link secret, timestamp + "." + payload),
 * the payload being the raw query string of a GET and the raw body of a POST.
 */
class EzChatStoreController extends Controller
{
    private const SKEW = 300;

    public function __construct(private readonly EzChatStore $store) {}

    public function products(Request $request, string $link): JsonResponse
    {
        return $this->signed($request, $link, fn (EzChatLink $l) => $this->store->products($l, (int) $request->query('page', '1')));
    }

    public function stock(Request $request, string $link): JsonResponse
    {
        return $this->signed($request, $link, fn (EzChatLink $l) => $this->store->stockOf($l, (string) $request->query('sku', ''), $request->query('variation') ? (string) $request->query('variation') : null));
    }

    public function orders(Request $request, string $link): JsonResponse
    {
        return $this->signed($request, $link, fn () => $this->store->byPhone((string) $request->query('phone', '')));
    }

    public function lookup(Request $request, string $link): JsonResponse
    {
        return $this->signed($request, $link, fn () => $this->store->lookup((string) $request->query('number', ''), $request->query('phone') ? (string) $request->query('phone') : null, $request->boolean('any')));
    }

    public function createOrder(Request $request, string $link): JsonResponse
    {
        return $this->signed($request, $link, fn (EzChatLink $l) => $this->store->createOrder($l, (array) $request->json()->all()));
    }

    public function cancel(Request $request, string $link, string $number): JsonResponse
    {
        return $this->signed($request, $link, fn () => ['ok' => $this->store->cancel($number)]);
    }

    /** "Ngắt kết nối" pressed in EzChat: the link goes here too, so the store shows it is no longer linked. */
    public function unlink(Request $request, string $link): JsonResponse
    {
        return $this->signed($request, $link, fn (EzChatLink $l) => ['ok' => (bool) $l->delete()]);
    }

    /** The store has no shipping table of its own for chat orders: EzChat keeps quoting its own fee. */
    public function shipping(Request $request, string $link): JsonResponse
    {
        return $this->signed($request, $link, fn () => ['fee' => null]);
    }

    /** @param  Closure(EzChatLink): mixed  $run */
    private function signed(Request $request, string $ulid, Closure $run): JsonResponse
    {
        $link = EzChatLink::withoutGlobalScope(TenantScope::class)->with('tenant')->where('ulid', $ulid)->first();
        $ts = (string) $request->header('X-EzChat-Timestamp', '');
        $signature = (string) $request->header('X-EzChat-Signature', '');
        $payload = $request->isMethod('GET') ? (string) $request->server('QUERY_STRING', '') : $request->getContent();
        if ($link === null || $link->tenant === null) {
            return response()->json(['message' => 'Cửa hàng đã ngắt kết nối EzChat. Nối lại trong EzChat Bán hàng.'], 404);
        }
        if (! ctype_digit($ts) || abs(time() - (int) $ts) > self::SKEW || ! hash_equals('sha256='.hash_hmac('sha256', $ts.'.'.$payload, $link->secret), $signature)) {
            return response()->json(['message' => 'Chữ ký không hợp lệ.'], 401);
        }

        return TenantContext::run($link->tenant, function () use ($run, $link) {
            try {
                return response()->json($run($link));
            } catch (ValidationException $e) {
                return response()->json(['message' => collect($e->errors())->flatten()->first() ?? 'Dữ liệu không hợp lệ.'], 422);
            }
        });
    }
}
