<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Sync Center overview of the current tenant: shops, task counts, open problems. Read only. */
class SyncDashboard
{
    /** Tokens expiring within this many days are flagged. */
    public const TOKEN_WARN_DAYS = 7;

    /** "Lỗi cần xử lý" counts failed tasks of this many days. */
    public const FAILED_DAYS = 7;

    public function __construct(
        private readonly TenantContext $context,
        private readonly SyncActivityQuery $activity,
        private readonly SyncHealth $health,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  account, marketplace, type, from, to (tasks default to the last 24 hours)
     * @return array<string, mixed>
     */
    public function overview(array $filters): array
    {
        $filters += ['from' => now()->subDay()->toIso8601String()];
        $accounts = MarketplaceAccount::query()->where('status', '!=', MarketplaceAccount::REVOKED)
            ->when($filters['account'] ?? null, fn ($q, $ulid) => $q->where('ulid', $ulid))
            ->when($filters['marketplace'] ?? null, fn ($q, $m) => $q->where('marketplace', $m))
            ->orderBy('shop_name')->get();
        $ids = $accounts->pluck('id');

        $expiring = MarketplaceCredential::query()->whereIn('marketplace_account_id', $ids)->whereNotNull('access_expires_at')
            ->where('access_expires_at', '<', now()->addDays(self::TOKEN_WARN_DAYS))->pluck('access_expires_at', 'marketplace_account_id');
        $lastPoll = SyncRun::query()->whereIn('marketplace_account_id', $ids)->where('resource', 'orders')
            ->selectRaw('marketplace_account_id, MAX(started_at) as at')->groupBy('marketplace_account_id')->pluck('at', 'marketplace_account_id');
        $lastWebhook = WebhookEvent::query()->where('tenant_id', $this->context->id())->whereIn('marketplace_account_id', $ids)
            ->selectRaw('marketplace_account_id, MAX(received_at) as at')->groupBy('marketplace_account_id')->pluck('at', 'marketplace_account_id');
        $openErrors = SyncError::query()->whereIn('marketplace_account_id', $ids)->whereNull('resolved_at')
            ->selectRaw('marketplace_account_id, COUNT(*) as n')->groupBy('marketplace_account_id')->pluck('n', 'marketplace_account_id');
        $pendingPush = InventorySyncOutbox::query()->whereIn('marketplace_account_id', $ids)->where('status', InventorySyncOutbox::PENDING)
            ->selectRaw('marketplace_account_id, COUNT(*) as n')->groupBy('marketplace_account_id')->pluck('n', 'marketplace_account_id');
        $drift = $this->driftQuery()->whereIn('marketplace_account_id', $ids)
            ->selectRaw('marketplace_account_id, COUNT(*) as n')->groupBy('marketplace_account_id')->pluck('n', 'marketplace_account_id');

        $deadLetters = InventorySyncOutbox::query()->whereIn('marketplace_account_id', $ids)->where('status', InventorySyncOutbox::FAILED)->count()
            + WebhookEvent::query()->where('tenant_id', $this->context->id())->whereIn('marketplace_account_id', $ids)->where('status', WebhookEvent::FAILED)
                ->where('received_at', '>=', now()->subDays(SyncHealth::KEEP_DAYS))->count();
        $errorsByCode = SyncError::query()->whereIn('marketplace_account_id', $ids)->whereNull('resolved_at')
            ->selectRaw('COALESCE(error_code, category) as code, COUNT(*) as n')->groupBy('code')->pluck('n', 'code')->map(fn ($n) => (int) $n);

        return [
            'accounts' => [
                'total' => $accounts->count(),
                'active' => $accounts->where('status', MarketplaceAccount::CONNECTED)->count(),
                'disconnected' => $accounts->whereIn('status', [MarketplaceAccount::EXPIRED, MarketplaceAccount::ERROR])->count(),
                'circuit_open' => $accounts->filter(fn (MarketplaceAccount $a) => $a->circuitOpen())->count(),
                'token_expiring' => $expiring->count(),
                'reconcile_overdue' => $this->health->overdueReconciles($this->context->id()),
            ],
            'tasks' => $this->activity->counts($filters),
            'problems' => [
                'open_errors' => (int) $openErrors->sum(),
                'errors_by_code' => (object) $errorsByCode->all(),
                'dead_letters' => $deadLetters,
                'stock_drift' => (int) $drift->sum(),
                'failed_by_code' => (object) $this->failedByCode($ids->all()),
            ],
            'last_synced_at' => $accounts->max('last_synced_at')?->toIso8601String(),
            'shops' => $accounts->map(fn (MarketplaceAccount $a) => [
                'id' => $a->ulid,
                'name' => $a->shop_name,
                'marketplace' => $a->marketplace,
                'status' => $a->status,
                'sync_enabled' => (bool) $a->sync_enabled,
                'circuit_open' => $a->circuitOpen(),
                'last_error' => $a->last_error,
                'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                'last_order_poll_at' => isset($lastPoll[$a->id]) ? Carbon::parse($lastPoll[$a->id])->toIso8601String() : null,
                'last_webhook_at' => isset($lastWebhook[$a->id]) ? Carbon::parse($lastWebhook[$a->id])->toIso8601String() : null,
                'token_expires_at' => isset($expiring[$a->id]) ? $expiring[$a->id]->toIso8601String() : null,
                'open_errors' => (int) ($openErrors[$a->id] ?? 0),
                'pending_push' => (int) ($pendingPush[$a->id] ?? 0),
                'stock_drift' => (int) ($drift[$a->id] ?? 0),
            ])->values(),
        ];
    }

    /**
     * Failed and dead-letter tasks of the last FAILED_DAYS days, per standard error code ("other" when the source keeps
     * no code, e.g. price pushes). Feeds "Lỗi cần xử lý".
     *
     * @param  array<int, int>  $accountIds
     * @return array<string, int>
     */
    public function failedByCode(array $accountIds): array
    {
        $since = now()->subDays(self::FAILED_DAYS);
        $sources = [
            WebhookEvent::query()->where('tenant_id', $this->context->id())->where('status', WebhookEvent::FAILED)->where('received_at', '>=', $since),
            InventorySyncOutbox::query()->whereIn('status', [InventorySyncOutbox::FAILED, InventorySyncOutbox::REJECTED, InventorySyncOutbox::UNSUPPORTED])->where('updated_at', '>=', $since),
            SyncRun::query()->whereIn('status', [SyncRun::FAILED, SyncRun::PARTIAL])->where('started_at', '>=', $since),
        ];
        $counts = [];
        foreach ($sources as $query) {
            $rows = $query->whereIn('marketplace_account_id', $accountIds)->selectRaw('error_code, COUNT(*) as n')->groupBy('error_code')->pluck('n', 'error_code');
            foreach ($rows as $code => $n) {
                $key = $code === '' || $code === null ? 'other' : (string) $code;
                $counts[$key] = ($counts[$key] ?? 0) + (int) $n;
            }
        }
        $prices = ListingVariant::query()->whereIn('marketplace_account_id', $accountIds)->where('price_push_status', 'failed')->count();
        if ($prices > 0) {
            $counts['other'] = ($counts['other'] ?? 0) + $prices;
        }
        arsort($counts);

        return $counts;
    }

    /**
     * Linked listings whose stock last read from the channel differs from what ECSO last published there (someone
     * changed it on the channel). ECSO stays the source of truth: the next push sets it back; this only reports.
     */
    /** @return Builder<ListingVariant> */
    public function driftQuery(): Builder
    {
        return ListingVariant::query()->where('mapping_status', ListingVariant::MAPPED)->where('push_status', 'ok')
            ->whereNotNull('marketplace_stock')->whereNotNull('last_pushed_qty')->whereColumn('marketplace_stock', '!=', 'last_pushed_qty');
    }
}
