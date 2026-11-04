<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Marketplace\Services\SyncErrorLog;
use App\Modules\Marketplace\Services\WooCommerceConnectService;
use App\Modules\Orders\Services\OrderSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Sync history (one row per pass) and sync errors (one row per order that could not be synced). */
class SyncLogController extends Controller
{
    public function runs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $filters = $request->validate([
            'account' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in([SyncRun::RUNNING, SyncRun::OK, SyncRun::PARTIAL, SyncRun::FAILED])],
        ]);

        $runs = SyncRun::query()->with('account:id,ulid,shop_name,marketplace')
            ->when($filters['account'] ?? null, fn ($q, $ulid) => $q->whereHas('account', fn ($a) => $a->where('ulid', $ulid)))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate(30);

        return response()->json([
            'data' => collect($runs->items())->map(fn (SyncRun $r) => [
                'id' => $r->ulid,
                'account' => $r->account ? ['id' => $r->account->ulid, 'name' => $r->account->shop_name, 'marketplace' => $r->account->marketplace] : null,
                'resource' => $r->resource,
                'trigger' => $r->trigger,
                'status' => $r->status,
                'window_from' => $r->window_from?->toIso8601String(),
                'window_to' => $r->window_to?->toIso8601String(),
                'pages' => $r->pages,
                'counts' => (object) ($r->counts ?? []),
                'error' => $r->error,
                'started_at' => $r->started_at->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(),
                'duration_ms' => $r->finished_at ? (int) $r->started_at->diffInMilliseconds($r->finished_at) : null,
            ]),
            'meta' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage(), 'total' => $runs->total()],
        ]);
    }

    public function errors(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $filters = $request->validate([
            'account' => ['sometimes', 'string'],
            'category' => ['sometimes', Rule::in(SyncError::CATEGORIES)],
            'state' => ['sometimes', Rule::in(['open', 'resolved'])],
        ]);

        $query = SyncError::query()->with('account:id,ulid,shop_name,marketplace')
            ->when($filters['account'] ?? null, fn ($q, $ulid) => $q->whereHas('account', fn ($a) => $a->where('ulid', $ulid)))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c));
        ($filters['state'] ?? 'open') === 'open' ? $query->whereNull('resolved_at') : $query->whereNotNull('resolved_at');
        $errors = $query->orderByDesc('last_seen_at')->paginate(30);

        $open = SyncError::query()->whereNull('resolved_at')->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category');

        return response()->json([
            'data' => collect($errors->items())->map(fn (SyncError $e) => $this->errorRow($e)),
            'meta' => [
                'current_page' => $errors->currentPage(), 'last_page' => $errors->lastPage(), 'total' => $errors->total(),
                'open_by_category' => (object) $open->map(fn ($n) => (int) $n)->all(),
            ],
        ]);
    }

    /** Reads the order from the store again; the error closes when it goes through. */
    public function retry(SyncError $error, OrderSync $sync, Request $request): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $account = $error->account;
        abort_unless($error->resource === 'order' && $account && $account->isActive(), 422, 'Website chưa kết nối.');

        try {
            $result = $sync->syncOne($account, $error->external_id, 'user', null, $request->user()->id);
        } catch (ConnectorException $e) {
            throw ValidationException::withMessages(['error' => $e->getMessage()]);
        }

        return response()->json(['data' => ['result' => $result['result'], 'error' => $this->errorRow($error->refresh())]]);
    }

    public function resolve(SyncError $error, SyncErrorLog $log, Request $request): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        if ($error->resolved_at === null) {
            $error->forceFill(['resolved_at' => now(), 'resolution' => SyncError::DISMISSED, 'resolved_by' => $request->user()->id])->save();
        }

        return response()->json(['data' => $this->errorRow($error)]);
    }

    /** "Đồng bộ ngay": one reconcile pass of this store, logged like the scheduled ones. */
    public function syncNow(MarketplaceAccount $account, OrderSync $sync): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        abort_unless($account->isActive() && $account->syncs('sync_orders'), 422, 'Website chưa kết nối hoặc đang tắt đồng bộ đơn.');

        try {
            $counts = $sync->syncRecent($account, trigger: 'user');
        } catch (ConnectorException $e) {
            throw ValidationException::withMessages(['account' => $e->getMessage()]);
        }

        return response()->json(['data' => ['counts' => (object) $counts]]);
    }

    public function checkWebhooks(MarketplaceAccount $account, WooCommerceConnectService $service): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        abort_unless($account->isActive() && $account->marketplace === WooCommerceConnector::MARKETPLACE, 422, 'Chỉ kiểm tra được webhook của website WooCommerce đang kết nối.');

        return response()->json(['data' => $service->checkWebhooks($account)]);
    }

    /** @return array<string, mixed> */
    private function errorRow(SyncError $e): array
    {
        return [
            'id' => $e->ulid,
            'account' => $e->account ? ['id' => $e->account->ulid, 'name' => $e->account->shop_name, 'marketplace' => $e->account->marketplace] : null,
            'resource' => $e->resource,
            'external_id' => $e->external_id,
            'category' => $e->category,
            'message' => $e->message,
            'attempts' => $e->attempts,
            'first_seen_at' => $e->first_seen_at->toIso8601String(),
            'last_seen_at' => $e->last_seen_at->toIso8601String(),
            'resolved_at' => $e->resolved_at?->toIso8601String(),
            'resolution' => $e->resolution,
        ];
    }
}
