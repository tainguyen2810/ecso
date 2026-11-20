<?php

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Services\LossReports;
use App\Modules\Messaging\Push\PushNotifier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Đợt 14 "Cảnh báo đơn lỗ": every 15 minutes, orders of the last 3 days that sell at a loss after fees and were not
 * reported yet go to the phones of members who may see profit (one message per store and pass), then are marked.
 */
class LossAlertsCommand extends Command
{
    public const DAYS = 3;

    protected $signature = 'ecso:orders:loss-alerts {--tenant= : Only this tenant id} {--dry-run : List, do not send or mark}';

    protected $description = 'Báo lên điện thoại các đơn mới bán lỗ sau phí sàn.';

    public function handle(): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            TenantContext::run($tenant, function () use ($tenant) {
                $orders = app(LossReports::class)->unalerted(now()->subDays(self::DAYS));
                if ($orders === []) {
                    return;
                }
                $message = self::message($orders);
                $this->line("{$tenant->name}: {$message['title']} — {$message['body']}");
                if ($this->option('dry-run')) {
                    return;
                }
                DB::table('orders')->whereIn('id', array_map(fn ($o) => $o->id, $orders))->update(['loss_alerted_at' => now()]);
                app(PushNotifier::class)->send('finance.profit', $message['title'], $message['body'], $message['url']);
            });
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<object>  $orders  rows of LossReports::unalerted()
     * @return array{title: string, body: string, url: string}
     */
    public static function message(array $orders): array
    {
        $money = fn (float $v) => number_format(abs($v), 0, ',', '.').'đ';
        if (count($orders) === 1) {
            $o = $orders[0];

            return [
                'title' => 'Đơn lỗ sau phí · '.$o->channel,
                'body' => "{$o->external_order_id} lỗ ".$money((float) $o->profit).' (thu '.$money((float) $o->net - (float) $o->fee).', vốn '.$money((float) $o->cogs).')',
                'url' => '/orders/'.$o->ulid,
            ];
        }
        $total = array_sum(array_map(fn ($o) => (float) $o->profit, $orders));

        return [
            'title' => count($orders).' đơn lỗ sau phí',
            'body' => 'Tổng lỗ '.$money($total).'. Xem đơn nào lỗ và sản phẩm nên tăng giá.',
            'url' => '/reports/view/loss-orders',
        ];
    }
}
