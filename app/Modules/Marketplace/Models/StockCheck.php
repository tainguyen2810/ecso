<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of "Đối soát tồn với sàn" for a shop (Đợt 16).
 *
 * @property list<array{sku: string|null, name: string, marketplace_qty: int, ecso_qty: int}>|null $items
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class StockCheck extends Model
{
    use BelongsToTenant;

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** SKUs kept in the list of a run. */
    public const MAX_ITEMS = 200;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<MarketplaceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    /** @return array<string, mixed> */
    public function summary(bool $withItems = false): array
    {
        return [
            'status' => $this->status,
            'trigger' => $this->trigger,
            'checked' => $this->checked,
            'mismatched' => $this->mismatched,
            'fixed' => $this->fixed,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            ...($withItems ? ['items' => $this->items ?? []] : []),
        ];
    }
}
