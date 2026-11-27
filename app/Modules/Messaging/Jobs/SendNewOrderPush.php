<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Models\PushSubscription;
use App\Modules\Messaging\Push\WebPush;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** "Báo đơn mới": the orders gathered in the last half minute, told to every phone of the store that asked. */
class SendNewOrderPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Older orders (the first pull of a new shop brings months of them) are not news. */
    public const RECENT_HOURS = 6;

    public int $tries = 1;

    public function __construct(public readonly int $tenantId) {}

    public function handle(WebPush $push): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $ids = (array) Cache::pull("push:new-orders:{$this->tenantId}", []);
        if ($tenant === null || $ids === []) {
            return;
        }
        TenantContext::run($tenant, function () use ($ids, $push) {
            $orders = Order::query()->with(['account', 'channel'])->whereIn('id', $ids)
                ->where(fn ($q) => $q->whereNull('placed_at')->orWhere('placed_at', '>=', now()->subHours(self::RECENT_HOURS)))
                ->orderByDesc('id')->get();
            if ($orders->isEmpty()) {
                return;
            }
            $message = self::message($orders->all());
            foreach (PushSubscription::query()->get() as $sub) {
                $sub->forceFill(['last_title' => $message['title'], 'last_body' => $message['body'], 'last_url' => $message['url'], 'last_sent_at' => now()])->save();
                try {
                    if (! $push->wake($sub->endpoint)) {
                        $sub->delete();
                    }
                } catch (Throwable $e) {
                    Log::warning('push failed', ['tenant' => $this->tenantId, 'error' => $e->getMessage()]);
                }
            }
        });
    }

    /**
     * @param  list<Order>  $orders  newest first
     * @return array{title: string, body: string, url: string}
     */
    public static function message(array $orders): array
    {
        $money = fn (Order $o) => number_format((float) $o->total, 0, ',', '.').'đ';
        $where = fn (Order $o) => $o->account->shop_name ?? $o->channel->name ?? 'Đơn mới';
        if (count($orders) === 1) {
            $o = $orders[0];

            return [
                'title' => 'Đơn mới · '.$where($o),
                'body' => trim($o->displayCode().' · '.($o->buyer_name ? $o->buyer_name.' · ' : '').$money($o)),
                'url' => '/orders/'.$o->ulid,
            ];
        }
        $total = array_sum(array_map(fn (Order $o) => (float) $o->total, $orders));

        return [
            'title' => count($orders).' đơn mới',
            'body' => 'Tổng '.number_format($total, 0, ',', '.').'đ · mới nhất '.$orders[0]->displayCode().' ('.$where($orders[0]).')',
            'url' => '/orders?tab=all',
        ];
    }
}
