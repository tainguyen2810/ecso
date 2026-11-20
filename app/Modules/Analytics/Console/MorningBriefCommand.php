<?php

namespace App\Modules\Analytics\Console;

use App\Modules\Inventory\Services\LowStockQuery;
use App\Modules\Marketplace\Models\StockCheck;
use App\Modules\Messaging\Push\PushNotifier;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Illuminate\Console\Command;

/**
 * Đợt 15 "Bản tin 8h sáng": one notification per store to the phones that asked for it — orders to ship today, those
 * late or due today, yesterday's sales, SKUs running low, marketplace returns on their way back and the SKUs whose
 * marketplace stock was set right during the night.
 */
class MorningBriefCommand extends Command
{
    /** Orders that still have to leave the shop. */
    public const TO_SHIP = [OrderStatus::PENDING_CONFIRMATION, OrderStatus::READY_TO_PACK, OrderStatus::PACKED];

    protected $signature = 'ecso:morning-brief {--tenant= : Only this tenant id} {--dry-run : Show, do not send}';

    protected $description = 'Gửi bản tin buổi sáng (đơn cần giao, sắp trễ, hàng sắp hết, hàng hoàn) lên điện thoại.';

    public function handle(LowStockQuery $lowStock): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            TenantContext::run($tenant, function () use ($tenant, $lowStock) {
                $message = self::message(self::figures($tenant, $lowStock));
                if ($message === null) {
                    return;
                }
                $this->line("{$tenant->name}: {$message['title']} — {$message['body']}");
                if (! $this->option('dry-run')) {
                    app(PushNotifier::class)->send('orders.view', $message['title'], $message['body'], $message['url'], brief: true);
                }
            });
        }

        return self::SUCCESS;
    }

    /**
     * The figures of the brief, in the store's time zone.
     *
     * @return array{date: string, to_ship: int, overdue: int, due_today: int, yesterday_orders: int, yesterday_sales: float, low_stock: int, returns_coming: int, stock_fixed: int}
     */
    public static function figures(Tenant $tenant, LowStockQuery $lowStock): array
    {
        $now = ReportRange::now();
        $endOfDay = $now->endOfDay()->utc();
        $yesterday = [$now->subDay()->startOfDay()->utc(), $now->subDay()->endOfDay()->utc()];
        $open = fn () => Order::query()->whereIn('status', self::TO_SHIP);
        $sold = Order::query()->whereNotIn('status', [OrderStatus::CANCELLED])->whereBetween('placed_at', $yesterday);

        return [
            'date' => $now->format('d/m'),
            'to_ship' => $open()->count(),
            'overdue' => $open()->where('ship_by_at', '<', now())->count(),
            'due_today' => $open()->whereBetween('ship_by_at', [now(), $endOfDay])->count(),
            'yesterday_orders' => (clone $sold)->count(),
            'yesterday_sales' => (float) (clone $sold)->sum('total'),
            'low_stock' => count($lowStock->forTenant($tenant->id, 200)),
            'returns_coming' => OrderReturn::query()->whereIn('status', [OrderReturn::APPROVED, OrderReturn::IN_TRANSIT])->count(),
            // Đợt 16: SKUs whose marketplace stock was wrong and set right during the night.
            'stock_fixed' => (int) StockCheck::query()->where('status', StockCheck::DONE)->where('created_at', '>=', $now->startOfDay()->utc())->sum('mismatched'),
        ];
    }

    /**
     * @param  array<string, mixed>  $f  figures()
     * @return array{title: string, body: string, url: string}|null null on a quiet day with nothing to say
     */
    public static function message(array $f): ?array
    {
        $parts = [];
        if ($f['to_ship'] > 0) {
            $late = array_filter([$f['overdue'] ? "{$f['overdue']} quá hạn" : null, $f['due_today'] ? "{$f['due_today']} hạn hôm nay" : null]);
            $parts[] = "Cần giao {$f['to_ship']} đơn".($late ? ' ('.implode(', ', $late).')' : '').'.';
        }
        if ($f['yesterday_orders'] > 0) {
            $parts[] = "Hôm qua {$f['yesterday_orders']} đơn, ".number_format($f['yesterday_sales'], 0, ',', '.').'đ.';
        }
        if ($f['low_stock'] > 0) {
            $parts[] = ($f['low_stock'] >= 200 ? '200+' : $f['low_stock']).' SKU sắp hết hàng.';
        }
        if ($f['returns_coming'] > 0) {
            $parts[] = "{$f['returns_coming']} đơn hoàn đang về.";
        }
        if (($f['stock_fixed'] ?? 0) > 0) {
            $parts[] = "Đêm qua sửa {$f['stock_fixed']} SKU lệch tồn trên sàn.";
        }
        if ($parts === []) {
            return null;
        }

        return [
            'title' => "Bản tin sáng {$f['date']}",
            'body' => implode(' ', $parts),
            'url' => $f['overdue'] > 0 ? '/orders?flags=overdue' : ($f['to_ship'] > 0 ? '/orders?tab=to_pack' : '/'),
        ];
    }
}
