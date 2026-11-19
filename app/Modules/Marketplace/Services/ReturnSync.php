<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\FetchesReturns;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\ReturnService;
use Carbon\CarbonImmutable;

/**
 * Đợt 6 "Hàng hoàn từ sàn": return and refund requests of a shop come into Hoàn/Trả by themselves. Each pass reads
 * what changed since the last one (the first pass looks back DAYS days), keeping the time in the shop's settings.
 */
class ReturnSync
{
    public const DAYS = 30;

    /** Pages read per pass, so one busy shop cannot hold the queue. */
    private const MAX_PAGES = 20;

    public function __construct(private readonly ConnectorRegistry $connectors, private readonly ReturnService $returns) {}

    public function supports(MarketplaceAccount $account): bool
    {
        return $this->connectors->has($account->marketplace) && $this->connectors->for($account->marketplace) instanceof FetchesReturns;
    }

    /** @return array{read: int, created: int, skipped: int} */
    public function syncAccount(MarketplaceAccount $account): array
    {
        $connector = $this->connectors->for($account->marketplace);
        if (! $connector instanceof FetchesReturns) {
            return ['read' => 0, 'created' => 0, 'skipped' => 0];
        }
        $started = CarbonImmutable::now();
        $last = $account->settings['returns_synced_at'] ?? null;
        // A little overlap: a request updated while the last pass ran is read again (the sync is idempotent).
        $since = is_string($last) ? CarbonImmutable::parse($last)->subMinutes(10) : $started->subDays(self::DAYS);
        $counts = ['read' => 0, 'created' => 0, 'skipped' => 0];

        $cursor = null;
        $pages = 0;
        do {
            $page = $connector->listReturns($account, $since, $cursor);
            foreach ($page['returns'] as $r) {
                $counts['read']++;
                $order = Order::query()->with('account')->where('marketplace_account_id', $account->id)->where('external_order_id', $r['order_id'])->first();
                if ($order === null) {
                    // An order older than the shop's order sync: nothing in the warehouse to take back.
                    $counts['skipped']++;

                    continue;
                }
                [, $new] = $this->returns->syncMarketplace($order, $r);
                $counts['created'] += (int) $new;
            }
            $cursor = $page['next'];
        } while ($cursor !== null && ++$pages < self::MAX_PAGES);

        if ($cursor === null) {
            $account->forceFill(['settings' => [...($account->settings ?? []), 'returns_synced_at' => $started->toIso8601String()]])->save();
        }

        return $counts;
    }
}
