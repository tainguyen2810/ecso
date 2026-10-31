<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Data\StockUpdate;
use App\Modules\Marketplace\Connectors\Data\StockUpdateResult;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Step [9]–[10] of INVENTORY-ENGINE §7: pushes due outbox rows of one shop, in batches, one job per shop at a time
 * (cache lock). Records the outcome per listing; temporary failures back off; repeated failures open the circuit.
 */
class PushInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $accountId)
    {
        $this->onQueue('sync');
    }

    public function handle(ConnectorRegistry $connectors): void
    {
        $account = MarketplaceAccount::query()->withoutGlobalScopes()->find($this->accountId);
        if (! $account || ! $account->isActive() || ! $account->sync_enabled) {
            return;
        }

        $tenant = Tenant::query()->find($account->tenant_id);
        $lock = Cache::lock("inventory-push:{$account->id}", 120);
        if (! $tenant || ! $lock->get()) {
            return; // another worker is pushing this shop; it picks up our rows
        }

        try {
            $next = TenantContext::run($tenant, fn () => $this->push(MarketplaceAccount::query()->findOrFail($account->id), $connectors));
        } finally {
            $lock->release();
        }

        if ($next !== null) {
            self::dispatch($account->id);
        }
    }

    /** @return Carbon|null when to run again */
    private function push(MarketplaceAccount $account, ConnectorRegistry $connectors): ?Carbon
    {
        if ($account->circuitOpen()) {
            return null;
        }

        $config = config('marketplaces.inventory_push');
        $rows = InventorySyncOutbox::query()->with('listingVariant.listing')
            ->where('marketplace_account_id', $account->id)
            ->where('status', InventorySyncOutbox::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->limit($config['batch_size'])->get();

        if ($rows->isNotEmpty()) {
            $this->send($account, $connectors, $rows);
        }

        // Only chain when more rows are due now (more than one batch). Rows waiting for a backoff are picked up by
        // the scheduler (ecso:marketplace:push-due), so a sync queue cannot loop.
        $moreDue = InventorySyncOutbox::query()->where('marketplace_account_id', $account->id)
            ->where('status', InventorySyncOutbox::PENDING)->where('next_attempt_at', '<=', now())->exists();

        return $moreDue && $rows->count() >= $config['batch_size'] ? now() : null;
    }

    /**
     * @param  Collection<int, InventorySyncOutbox>  $rows
     */
    private function send(MarketplaceAccount $account, ConnectorRegistry $connectors, Collection $rows): void
    {
        $updates = $rows->map(fn (InventorySyncOutbox $row) => new StockUpdate(
            (string) ($row->listingVariant->listing?->external_product_id ?? ''),
            $row->listingVariant->external_sku_id,
            $row->desired_qty,
        ))->all();

        try {
            $results = collect($connectors->for($account->marketplace)->updateInventory($account, $updates))->keyBy('externalSkuId');
        } catch (UnsupportedCapability $e) {
            // e.g. a marketplace without a stock API: shown to the seller as "update manually".
            foreach ($rows as $row) {
                $this->settle($row, InventorySyncOutbox::UNSUPPORTED, 'unsupported', $e->getMessage());
            }

            return;
        } catch (ConnectorException $e) {
            $this->failBatch($account, $rows, $e);

            return;
        }

        foreach ($rows as $row) {
            $result = $results->get($row->listingVariant->external_sku_id);
            match ($result?->status) {
                StockUpdateResult::OK => $this->succeed($row),
                StockUpdateResult::REJECTED => $this->settle($row, InventorySyncOutbox::REJECTED, 'rejected', $result->message),
                default => $this->retry($row, $result?->message ?? 'Sàn không trả kết quả cho SKU này'),
            };
        }

        $account->forceFill(['consecutive_failures' => 0, 'circuit_open_until' => null, 'last_error' => null])->save();
    }

    private function succeed(InventorySyncOutbox $row): void
    {
        $row->listingVariant->forceFill([
            'last_pushed_qty' => $row->desired_qty,
            'last_pushed_at' => now(),
            'push_status' => 'ok',
            'push_error' => null,
            'marketplace_stock' => $row->desired_qty,
        ])->save();

        // If a newer value arrived while we were pushing, the row stays pending for the next run.
        InventorySyncOutbox::query()->whereKey($row->id)->where('desired_qty', $row->desired_qty)
            ->where('updated_at', $row->updated_at)->update(['status' => InventorySyncOutbox::SUCCEEDED, 'last_error' => null]);
    }

    private function settle(InventorySyncOutbox $row, string $status, string $pushStatus, ?string $message): void
    {
        $row->forceFill(['status' => $status, 'last_error' => $message ? mb_substr($message, 0, 500) : null])->save();
        $row->listingVariant->forceFill(['push_status' => $pushStatus, 'push_error' => $message ? mb_substr($message, 0, 500) : null])->save();
    }

    private function retry(InventorySyncOutbox $row, string $message): void
    {
        $config = config('marketplaces.inventory_push');
        $attempts = $row->attempts + 1;

        if ($attempts >= $config['max_attempts']) {
            $this->settle($row, InventorySyncOutbox::FAILED, 'failed', $message);

            return;
        }

        $delay = $config['backoff'][min($attempts - 1, count($config['backoff']) - 1)];
        $row->forceFill([
            'attempts' => $attempts,
            'next_attempt_at' => now()->addSeconds($delay + random_int(0, (int) ceil($delay / 5))),
            'last_error' => mb_substr($message, 0, 500),
        ])->save();
        $row->listingVariant->forceFill(['push_status' => 'retrying', 'push_error' => mb_substr($message, 0, 500)])->save();
    }

    /**
     * @param  Collection<int, InventorySyncOutbox>  $rows
     */
    private function failBatch(MarketplaceAccount $account, Collection $rows, ConnectorException $e): void
    {
        foreach ($rows as $row) {
            $e->temporary ? $this->retry($row, $e->getMessage()) : $this->settle($row, InventorySyncOutbox::REJECTED, 'rejected', $e->getMessage());
        }

        $failures = $account->consecutive_failures + 1;
        $config = config('marketplaces.inventory_push');
        $account->forceFill([
            'consecutive_failures' => $failures,
            'last_error' => mb_substr($e->getMessage(), 0, 500),
            'circuit_open_until' => $failures >= $config['circuit_threshold'] ? now()->addSeconds($config['circuit_open_seconds']) : null,
            'status' => $e->authExpired ? MarketplaceAccount::EXPIRED : $account->status,
        ])->save();
    }
}
