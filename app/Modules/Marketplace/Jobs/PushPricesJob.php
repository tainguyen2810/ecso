<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Data\PriceUpdate;
use App\Modules\Marketplace\Connectors\Data\StockUpdateResult;
use App\Modules\Marketplace\Connectors\UpdatesPrices;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Pushes ECSO's price to the website SKUs of one shop that are waiting for it (price_push_status = pending).
 * Temporary errors (timeouts, 5xx) are retried by the queue; a refused SKU is marked failed with the reason.
 */
class PushPricesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BATCH = 50;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $accountId)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(ConnectorRegistry $connectors): void
    {
        $account = MarketplaceAccount::query()->withoutGlobalScopes()->find($this->accountId);
        $tenant = $account ? Tenant::query()->find($account->tenant_id) : null;
        if (! $account || ! $tenant || ! $account->isActive() || ! $account->syncs('sync_prices')) {
            return;
        }

        TenantContext::run($tenant, function () use ($account, $connectors) {
            $connector = $connectors->for($account->marketplace);
            if (! $connector instanceof UpdatesPrices) {
                return;
            }

            do {
                $rows = ListingVariant::query()->with('listing')->where('marketplace_account_id', $account->id)
                    ->where('price_push_status', 'pending')->whereNotNull('target_price')->orderBy('id')->limit(self::BATCH)->get();
                if ($rows->isEmpty()) {
                    return;
                }
                $this->send($connector, $account, $rows->all());
            } while ($rows->count() === self::BATCH);
        });
    }

    /** @param  array<int, ListingVariant>  $rows */
    private function send(UpdatesPrices $connector, MarketplaceAccount $account, array $rows): void
    {
        $updates = array_map(fn (ListingVariant $r) => new PriceUpdate((string) ($r->listing->external_product_id ?? ''), $r->external_sku_id, (string) $r->target_price), $rows);

        try {
            $results = collect($connector->updatePrices($account, $updates))->keyBy('externalSkuId');
        } catch (ConnectorException $e) {
            if ($e->temporary && $this->attempts() < $this->tries) {
                throw $e;
            }
            foreach ($rows as $row) {
                $row->forceFill(['price_push_status' => 'failed', 'price_push_error' => mb_substr($e->getMessage(), 0, 255)])->save();
            }

            return;
        }

        foreach ($rows as $row) {
            $result = $results->get($row->external_sku_id);
            if ($result?->status === StockUpdateResult::OK) {
                $row->forceFill([
                    'pushed_price' => $row->target_price,
                    'regular_price' => $row->target_price,
                    'price_pushed_at' => now(),
                    'price_push_status' => 'ok',
                    'price_push_error' => null,
                ])->save();
            } else {
                $row->forceFill(['price_push_status' => 'failed', 'price_push_error' => mb_substr($result->message ?? 'Website không trả kết quả cho SKU này', 0, 255)])->save();
            }
        }
    }
}
