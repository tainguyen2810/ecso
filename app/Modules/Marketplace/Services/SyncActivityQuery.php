<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Domain\SyncErrorCode;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The Sync Center queue: one list of sync tasks read from the rows the sync machinery already keeps. Nothing here
 * runs or schedules work; it is a read model (docs/SYNC_CENTER_AUDIT.md §4, Phase 2).
 *
 *   webhook     webhook_events         one marketplace event (order or product changed)
 *   stock_push  inventory_sync_outbox  the stock to publish for one listing (latest value wins)
 *   order_poll  sync_runs              one pass reading orders changed in a time window
 *   price_push  listing_variants       ECSO's price waiting for / sent to one website SKU
 *
 * Runs in the tenant context. webhook_events has no tenant scope, so it is filtered by tenant_id here.
 */
class SyncActivityQuery
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const RETRY_SCHEDULED = 'retry_scheduled';

    public const CANCELLED = 'cancelled';

    public const DEAD_LETTER = 'dead_letter';

    public const STATUSES = [self::PENDING, self::PROCESSING, self::SUCCESS, self::FAILED, self::RETRY_SCHEDULED, self::CANCELLED, self::DEAD_LETTER];

    public const TYPES = ['webhook', 'stock_push', 'order_poll', 'price_push'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{status?: string, type?: string, account?: string, marketplace?: string, from?: string, to?: string, q?: string, error_code?: string}  $filters
     * @return array{data: Collection<int, array<string, mixed>>, total: int}
     */
    public function list(array $filters, int $page = 1, int $perPage = 30): array
    {
        $take = $page * $perPage;
        $rows = collect();
        $total = 0;
        foreach ($this->types($filters) as $type) {
            $query = $this->query($type, $filters);
            if ($query === null) {
                continue;
            }
            $total += (clone $query)->count();
            $rows = $rows->concat($query->orderByDesc($this->timeColumn($type))->orderByDesc('id')->limit($take)->get()
                ->map(fn ($model) => $this->row($type, $model)));
        }

        return [
            'data' => $rows->sortByDesc(fn ($r) => $r['at'].str_pad((string) $r['key'], 12, '0', STR_PAD_LEFT))->slice(($page - 1) * $perPage, $perPage)->values(),
            'total' => $total,
        ];
    }

    /**
     * Tasks per normalized status (and per type), within the filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array{by_status: array<string, int>, by_type: array<string, array<string, int>>}
     */
    public function counts(array $filters): array
    {
        $byStatus = array_fill_keys(self::STATUSES, 0);
        $byType = [];
        foreach ($this->types($filters) as $type) {
            foreach (self::STATUSES as $status) {
                $query = $this->query($type, ['status' => $status] + $filters);
                $n = $query ? $query->count() : 0;
                $byStatus[$status] += $n;
                $byType[$type][$status] = $n;
            }
        }

        return ['by_status' => $byStatus, 'by_type' => $byType];
    }

    /** Finds one task by its key ("type:id"). */
    public function find(string $type, int $id): ?Model
    {
        $query = $this->query($type, []);

        return $query?->whereKey($id)->first();
    }

    /** @return array<string, mixed> */
    public function describe(string $type, Model $model): array
    {
        return $this->row($type, $model);
    }

    // -------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function types(array $filters): array
    {
        return isset($filters['type']) ? array_values(array_intersect(self::TYPES, [$filters['type']])) : self::TYPES;
    }

    private function timeColumn(string $type): string
    {
        return match ($type) {
            'webhook' => 'received_at',
            'order_poll' => 'started_at',
            default => 'updated_at',
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder|null null when the status cannot occur for the type
     */
    private function query(string $type, array $filters): ?Builder
    {
        $query = match ($type) {
            'webhook' => WebhookEvent::query()->where('tenant_id', $this->context->id()),
            'stock_push' => InventorySyncOutbox::query()->with('listingVariant:id,seller_sku,external_sku_id,name'),
            'order_poll' => SyncRun::query(),
            'price_push' => ListingVariant::query()->whereIn('price_push_status', ['pending', 'failed', 'ok'])
                ->where(fn ($q) => $q->where('price_push_status', '!=', 'ok')->orWhereNotNull('price_pushed_at')),
        };
        if (isset($filters['status']) && ! $this->whereStatus($type, $query, (string) $filters['status'])) {
            return null;
        }

        if (! empty($filters['account'])) {
            $query->whereIn('marketplace_account_id', MarketplaceAccount::query()->where('ulid', $filters['account'])->select('id'));
        }
        if (! empty($filters['marketplace'])) {
            $query->whereIn('marketplace_account_id', MarketplaceAccount::query()->where('marketplace', $filters['marketplace'])->select('id'));
        }
        $time = $this->timeColumn($type);
        if (! empty($filters['from'])) {
            $query->where($time, '>=', CarbonImmutable::parse($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->where($time, '<=', CarbonImmutable::parse($filters['to']));
        }
        if (! empty($filters['error_code'])) {
            if ($type === 'price_push') {
                return null; // price pushes keep a message, not a code
            }
            $query->where('error_code', $filters['error_code']);
        }
        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            match ($type) {
                'webhook' => $query->where(fn ($w) => $w->where('external_order_id', $q)->orWhere('external_product_id', $q)->orWhere('correlation_id', $q)),
                'stock_push' => $query->where(fn ($w) => $w->where('correlation_id', $q)->orWhereHas('listingVariant', fn ($l) => $l->where('seller_sku', $q)->orWhere('external_sku_id', $q))),
                'order_poll' => $query->where('correlation_id', $q),
                'price_push' => $query->where(fn ($w) => $w->where('seller_sku', $q)->orWhere('external_sku_id', $q)),
            };
        }

        return $query;
    }

    /** Narrows to one normalized status; false when that status cannot occur for the type. */
    private function whereStatus(string $type, Builder $query, string $status): bool
    {
        $map = match ($type) {
            'webhook' => [
                self::PENDING => fn ($q) => $q->where('status', WebhookEvent::RECEIVED)->where('attempts', 0),
                self::RETRY_SCHEDULED => fn ($q) => $q->where('status', WebhookEvent::RECEIVED)->where('attempts', '>', 0),
                self::SUCCESS => fn ($q) => $q->whereIn('status', [WebhookEvent::PROCESSED, WebhookEvent::IGNORED]),
                self::DEAD_LETTER => fn ($q) => $q->where('status', WebhookEvent::FAILED),
                self::CANCELLED => fn ($q) => $q->where('status', WebhookEvent::CANCELLED),
            ],
            'stock_push' => [
                self::PENDING => fn ($q) => $q->where('status', InventorySyncOutbox::PENDING)->where('attempts', 0),
                self::RETRY_SCHEDULED => fn ($q) => $q->where('status', InventorySyncOutbox::PENDING)->where('attempts', '>', 0),
                self::SUCCESS => fn ($q) => $q->where('status', InventorySyncOutbox::SUCCEEDED),
                self::FAILED => fn ($q) => $q->whereIn('status', [InventorySyncOutbox::REJECTED, InventorySyncOutbox::UNSUPPORTED]),
                self::DEAD_LETTER => fn ($q) => $q->where('status', InventorySyncOutbox::FAILED),
                self::CANCELLED => fn ($q) => $q->where('status', InventorySyncOutbox::CANCELLED),
            ],
            'order_poll' => [
                self::PROCESSING => fn ($q) => $q->where('status', SyncRun::RUNNING),
                self::SUCCESS => fn ($q) => $q->where('status', SyncRun::OK),
                self::FAILED => fn ($q) => $q->whereIn('status', [SyncRun::PARTIAL, SyncRun::FAILED]),
            ],
            'price_push' => [
                self::PENDING => fn ($q) => $q->where('price_push_status', 'pending'),
                self::SUCCESS => fn ($q) => $q->where('price_push_status', 'ok'),
                self::FAILED => fn ($q) => $q->where('price_push_status', 'failed'),
            ],
        };
        if (! isset($map[$status])) {
            return false;
        }
        $map[$status]($query);

        return true;
    }

    /** @return array<string, mixed> */
    private function row(string $type, Model $m): array
    {
        $accounts = $this->accounts();
        $account = $accounts->get($m->marketplace_account_id);
        $base = [
            'id' => "{$type}:{$m->id}",
            'key' => $m->id,
            'type' => $type,
            'account' => $account ? ['id' => $account->ulid, 'name' => $account->shop_name, 'marketplace' => $account->marketplace] : null,
        ];

        $row = match ($type) {
            'webhook' => [
                'status' => match ($m->status) {
                    WebhookEvent::RECEIVED => $m->attempts > 0 ? self::RETRY_SCHEDULED : self::PENDING,
                    WebhookEvent::PROCESSED, WebhookEvent::IGNORED => self::SUCCESS,
                    WebhookEvent::FAILED => self::DEAD_LETTER,
                    WebhookEvent::CANCELLED => self::CANCELLED,
                    default => self::FAILED,
                },
                'raw_status' => $m->status,
                'object' => $m->external_order_id !== null ? "Đơn {$m->external_order_id}" : ($m->external_product_id !== null ? "Sản phẩm {$m->external_product_id}" : null),
                'action' => $m->event_type,
                'attempts' => (int) $m->attempts,
                'next_retry_at' => null,
                'message' => $m->result,
                'error_code' => $m->error_code,
                'correlation_id' => $m->correlation_id,
                'created_at' => $m->received_at?->toIso8601String(),
                'started_at' => null,
                'completed_at' => $m->processed_at?->toIso8601String(),
                'at' => $m->received_at?->format('YmdHis'),
            ],
            'stock_push' => [
                'status' => match ($m->status) {
                    InventorySyncOutbox::PENDING => $m->attempts > 0 ? self::RETRY_SCHEDULED : self::PENDING,
                    InventorySyncOutbox::SUCCEEDED => self::SUCCESS,
                    InventorySyncOutbox::FAILED => self::DEAD_LETTER,
                    InventorySyncOutbox::CANCELLED => self::CANCELLED,
                    default => self::FAILED,
                },
                'raw_status' => $m->status,
                'object' => $m->listingVariant ? trim(($m->listingVariant->seller_sku ?? $m->listingVariant->external_sku_id).' · '.$m->listingVariant->name, ' ·') : null,
                'action' => "Đẩy tồn = {$m->desired_qty}",
                'attempts' => (int) $m->attempts,
                'next_retry_at' => $m->status === InventorySyncOutbox::PENDING ? $m->next_attempt_at?->toIso8601String() : null,
                'message' => $m->last_error,
                'error_code' => $m->error_code,
                'correlation_id' => $m->correlation_id,
                'created_at' => $m->created_at?->toIso8601String(),
                'started_at' => $m->last_attempt_at?->toIso8601String(),
                'completed_at' => in_array($m->status, [InventorySyncOutbox::PENDING], true) ? null : $m->updated_at?->toIso8601String(),
                'at' => $m->updated_at?->format('YmdHis'),
            ],
            'order_poll' => [
                'status' => match ($m->status) {
                    SyncRun::RUNNING => self::PROCESSING,
                    SyncRun::OK => self::SUCCESS,
                    default => self::FAILED,
                },
                'raw_status' => $m->status,
                'object' => 'Đơn hàng thay đổi',
                'action' => $m->trigger === 'user' ? 'Đồng bộ thủ công' : 'Đối soát định kỳ',
                'attempts' => 1,
                'next_retry_at' => null,
                'message' => $m->error,
                'error_code' => $m->error_code,
                'correlation_id' => $m->correlation_id,
                'counts' => (object) ($m->counts ?? []),
                'created_at' => $m->started_at?->toIso8601String(),
                'started_at' => $m->started_at?->toIso8601String(),
                'completed_at' => $m->finished_at?->toIso8601String(),
                'at' => $m->started_at?->format('YmdHis'),
            ],
            'price_push' => [
                'status' => match ($m->price_push_status) {
                    'pending' => self::PENDING,
                    'ok' => self::SUCCESS,
                    default => self::FAILED,
                },
                'raw_status' => $m->price_push_status,
                'object' => trim(($m->seller_sku ?? $m->external_sku_id).' · '.$m->name, ' ·'),
                'action' => 'Đẩy giá = '.number_format((float) $m->target_price, 0, ',', '.').' đ',
                'attempts' => null,
                'next_retry_at' => null,
                'message' => $m->price_push_error,
                'error_code' => null,
                'correlation_id' => null,
                'created_at' => $m->updated_at?->toIso8601String(),
                'started_at' => null,
                'completed_at' => $m->price_pushed_at?->toIso8601String(),
                'at' => ($m->price_push_status === 'ok' ? $m->price_pushed_at : $m->updated_at)?->format('YmdHis'),
            ],
        };
        $row['error_label'] = SyncErrorCode::label($row['error_code']);
        $row['duration_ms'] = $row['started_at'] && $row['completed_at']
            ? max(0, (int) CarbonImmutable::parse($row['started_at'])->diffInMilliseconds(CarbonImmutable::parse($row['completed_at']))) : null;
        $row['can_retry'] = in_array($row['status'], [self::FAILED, self::DEAD_LETTER], true);
        $row['can_cancel'] = in_array($type, ['webhook', 'stock_push'], true) && in_array($row['status'], [self::PENDING, self::RETRY_SCHEDULED], true);
        $row['at'] = (string) $row['at'];

        return $base + $row;
    }

    /** @var Collection<int, MarketplaceAccount>|null */
    private ?Collection $accounts = null;

    /** @return Collection<int, MarketplaceAccount> */
    private function accounts(): Collection
    {
        return $this->accounts ??= MarketplaceAccount::query()->get(['id', 'ulid', 'shop_name', 'marketplace'])->keyBy('id');
    }
}
