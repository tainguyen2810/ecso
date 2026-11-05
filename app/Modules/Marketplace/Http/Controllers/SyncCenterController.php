<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Marketplace\Domain\SyncErrorCode;
use App\Modules\Marketplace\Jobs\PushInventoryJob;
use App\Modules\Marketplace\Jobs\PushPricesJob;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Marketplace\Services\InventorySyncPlanner;
use App\Modules\Marketplace\Services\SyncActivityQuery;
use App\Modules\Marketplace\Services\SyncDashboard;
use App\Modules\Orders\Jobs\ProcessWebhookEvent;
use App\Modules\Orders\Jobs\ReconcileShopJob;
use App\Shared\Support\SafeRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sync Center API (docs/SYNC_CENTER_AUDIT.md §15): overview, the task queue read from the existing sync rows, and
 * the safe operations on them. Accounts and API keys stay on Kết nối sàn; this only operates the sync.
 * Every retry is idempotent by construction: a webhook re-reads the order, a stock push re-computes and sets an
 * absolute quantity, a price push sets an absolute price, an order poll re-reads a time window.
 */
class SyncCenterController extends Controller
{
    private const EXPORT_LIMIT = 5000;

    public function __construct(
        private readonly SyncActivityQuery $activity,
        private readonly AuditLogger $audit,
    ) {}

    public function overview(Request $request, SyncDashboard $dashboard): JsonResponse
    {
        $this->authorize('viewSync', MarketplaceAccount::class);

        return response()->json(['data' => $dashboard->overview($this->filters($request)) + [
            'error_codes' => collect(SyncErrorCode::INFO)->map(fn ($i) => ['label' => $i[0], 'hint' => $i[1], 'auto_retry' => $i[2]]),
            'permissions' => [
                'operate' => $request->user()->can('operateSync', MarketplaceAccount::class),
                'export' => $request->user()->can('exportSync', MarketplaceAccount::class),
            ],
        ]]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $this->authorize('viewSync', MarketplaceAccount::class);
        $page = max(1, (int) $request->query('page', 1));
        $result = $this->activity->list($this->filters($request), $page);

        return response()->json([
            'data' => $result['data'],
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($result['total'] / 30)), 'total' => $result['total']],
        ]);
    }

    /** One task and everything that shares its correlation id (webhook → order poll → stock pushes). */
    public function task(string $type, int $id): JsonResponse
    {
        $this->authorize('viewSync', MarketplaceAccount::class);
        $model = $this->findTask($type, $id);
        $row = $this->activity->describe($type, $model);
        $related = $row['correlation_id'] ? $this->activity->list(['q' => $row['correlation_id']], 1, 50)['data']->where('id', '!=', $row['id'])->values() : collect();

        return response()->json(['data' => $row + ['related' => $related]]);
    }

    public function retry(Request $request, string $type, int $id, InventorySyncPlanner $planner): JsonResponse
    {
        $this->authorize('operateSync', MarketplaceAccount::class);
        $model = $this->findTask($type, $id);
        $row = $this->activity->describe($type, $model);
        if (! $row['can_retry']) {
            throw ValidationException::withMessages(['task' => 'Chỉ thử lại được tác vụ thất bại.']);
        }
        $account = MarketplaceAccount::query()->findOrFail($model->marketplace_account_id);
        if (! $account->isActive()) {
            throw ValidationException::withMessages(['task' => 'Shop đang mất kết nối. Kết nối lại ở trang Kết nối sàn trước khi thử lại.']);
        }

        match ($type) {
            'webhook' => $this->retryWebhook($model),
            'stock_push' => $this->retryStock($model, $account, $planner),
            'order_poll' => ReconcileShopJob::dispatch($account->id, 'user'),
            'price_push' => $this->retryPrice($model, $account),
        };
        $this->audit->record('sync.task_retried', $account, ['task' => $row['id'], 'object' => $row['object']]);

        return response()->json(['data' => $this->activity->describe($type, $this->findTask($type, $id))]);
    }

    public function cancel(string $type, int $id): JsonResponse
    {
        $this->authorize('operateSync', MarketplaceAccount::class);
        $model = $this->findTask($type, $id);
        $row = $this->activity->describe($type, $model);
        if (! $row['can_cancel']) {
            throw ValidationException::withMessages(['task' => 'Chỉ hủy được tác vụ đang chờ.']);
        }

        match ($type) {
            'webhook' => WebhookEvent::query()->whereKey($model->id)->where('status', WebhookEvent::RECEIVED)
                ->update(['status' => WebhookEvent::CANCELLED, 'result' => 'Đã hủy từ Trung tâm đồng bộ', 'processed_at' => now()]),
            'stock_push' => InventorySyncOutbox::query()->whereKey($model->id)->where('status', InventorySyncOutbox::PENDING)
                ->update(['status' => InventorySyncOutbox::CANCELLED, 'last_error' => 'Đã hủy từ Trung tâm đồng bộ']),
        };
        $this->audit->record('sync.task_cancelled', MarketplaceAccount::query()->find($model->marketplace_account_id), ['task' => $row['id'], 'object' => $row['object']]);

        return response()->json(['data' => $this->activity->describe($type, $this->findTask($type, $id))]);
    }

    /** "Đồng bộ ngay" for any shop that can poll orders: queued, so the request never waits on the store. */
    public function syncNow(MarketplaceAccount $account): JsonResponse
    {
        $this->authorize('operateSync', MarketplaceAccount::class);
        abort_unless($account->isActive() && $account->sync_enabled && $account->syncs('sync_orders'), 422, 'Shop chưa kết nối hoặc đang tắt đồng bộ đơn.');
        ReconcileShopJob::dispatch($account->id, 'user');
        $this->audit->record('sync.sync_now', $account, []);

        return response()->json(['data' => ['queued' => true]], 202);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('exportSync', MarketplaceAccount::class);
        $filters = $this->filters($request);
        $rows = collect();
        for ($page = 1; $rows->count() < self::EXPORT_LIMIT; $page++) {
            $chunk = $this->activity->list($filters, $page, 500)['data'];
            $rows = $rows->concat($chunk);
            if ($chunk->count() < 500) {
                break;
            }
        }
        $this->audit->record('sync.logs_exported', null, ['filters' => $filters, 'rows' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $writer->addRow(SafeRow::fromValues(['Thời gian', 'Shop', 'Kênh', 'Loại', 'Đối tượng', 'Hành động', 'Trạng thái', 'Mã lỗi', 'Thông báo', 'Số lần thử', 'Thời gian xử lý (ms)', 'Correlation ID']));
            foreach ($rows->take(self::EXPORT_LIMIT) as $r) {
                $writer->addRow(SafeRow::fromValues([
                    $r['created_at'], $r['account']['name'] ?? '', $r['account']['marketplace'] ?? '', $r['type'], $r['object'], $r['action'],
                    $r['status'], $r['error_code'], $r['message'], $r['attempts'], $r['duration_ms'], $r['correlation_id'],
                ]));
            }
            $writer->close();
        }, 'nhat-ky-dong-bo-'.now()->format('Ymd-His').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    // -------------------------------------------------------------------------------------------------------------

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        return array_filter($request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(SyncActivityQuery::STATUSES)],
            'type' => ['sometimes', 'nullable', Rule::in(SyncActivityQuery::TYPES)],
            'account' => ['sometimes', 'nullable', 'string', 'max:26'],
            'marketplace' => ['sometimes', 'nullable', 'string', 'max:20'],
            'error_code' => ['sometimes', 'nullable', Rule::in(SyncErrorCode::all())],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]), fn ($v) => $v !== null && $v !== '');
    }

    private function findTask(string $type, int $id): Model
    {
        abort_unless(in_array($type, SyncActivityQuery::TYPES, true), 404);

        return $this->activity->find($type, $id) ?? abort(404);
    }

    private function retryWebhook(Model $event): void
    {
        assert($event instanceof WebhookEvent);
        $event->forceFill(['status' => WebhookEvent::RECEIVED, 'attempts' => 0, 'error_code' => null, 'result' => 'Thử lại từ Trung tâm đồng bộ', 'processed_at' => null])->save();
        ProcessWebhookEvent::dispatch($event->id);
    }

    /** The quantity is computed again from current stock, never the old value. */
    private function retryStock(Model $row, MarketplaceAccount $account, InventorySyncPlanner $planner): void
    {
        assert($row instanceof InventorySyncOutbox);
        $listing = ListingVariant::query()->find($row->listing_variant_id);
        if (! $listing || ! $listing->isMapped()) {
            throw ValidationException::withMessages(['task' => 'Sản phẩm sàn chưa liên kết SKU EzSell.']);
        }
        if (! $account->sync_enabled || ! $account->syncs('sync_stock')) {
            throw ValidationException::withMessages(['task' => 'Shop đang tắt đồng bộ tồn.']);
        }
        $planner->plan([(int) $listing->product_variant_id], force: true);
        PushInventoryJob::dispatch($account->id);
    }

    private function retryPrice(Model $listing, MarketplaceAccount $account): void
    {
        assert($listing instanceof ListingVariant);
        if (! $account->syncs('sync_prices') || $listing->target_price === null) {
            throw ValidationException::withMessages(['task' => 'Shop đang tắt đồng bộ giá.']);
        }
        $listing->forceFill(['price_push_status' => 'pending', 'price_push_error' => null])->save();
        PushPricesJob::dispatch($account->id);
    }
}
