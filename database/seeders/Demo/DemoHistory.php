<?php

namespace Database\Seeders\Demo;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryDocumentService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Services\ManualOrderService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily own-channel sales for the last $days days and today, so the dashboard, reports and cash book have a history. Each day
 * runs with the clock set to that day, so orders, payments, stock movements and vouchers carry consistent dates.
 * Stock is topped up every week the way a shop would restock. Deterministic (fixed seed).
 */
class DemoHistory
{
    public function __construct(private readonly ManualOrderService $orders, private readonly InventoryDocumentService $documents) {}

    /** @return int number of orders created */
    public function run(int $days): int
    {
        mt_srand(3110);
        $tenantId = app(TenantContext::class)->id();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $channels = SalesChannel::query()->where('is_active', true)->get();
        $customers = Customer::query()->where('status', 'active')->pluck('ulid')->all();
        $variants = ProductVariant::query()->whereHas('product', fn ($q) => $q->where('type', '!=', Product::TYPE_COMBO))->orderBy('id')->get();
        $created = 0;

        $today = Carbon::now();
        for ($back = $days; $back >= 0; $back--) {
            $day = $today->copy()->subDays($back)->startOfDay();
            if ($back % 7 === 0) {
                Carbon::setTestNow($day->copy()->setTime(8, 0));
                $this->restock($warehouse, $variants, $tenantId);
            }
            // Busier at weekends and towards today.
            $count = $back === 0 && $today->hour === 0 ? 0 : mt_rand(1, 3) + ($day->isWeekend() ? 2 : 0) + ($back < $days / 3 ? 1 : 0);
            for ($n = 0; $n < $count; $n++) {
                // Today only up to the current hour, so nothing is dated in the future.
                $hour = $back === 0 ? mt_rand(0, max(0, $today->hour - 1)) : mt_rand(8, 21);
                Carbon::setTestNow($day->copy()->setTime($hour, mt_rand(0, 59)));
                $lines = [];
                $amount = '0';
                foreach ((array) array_rand($variants->all(), mt_rand(1, 3)) as $i) {
                    $variant = $variants[$i];
                    $qty = mt_rand(1, 3);
                    $available = (int) DB::table('stock_balances')->where('tenant_id', $tenantId)->where('warehouse_id', $warehouse->id)
                        ->where('product_variant_id', $variant->id)->value(DB::raw('on_hand - reserved'));
                    if ($available < $qty) {
                        continue;
                    }
                    $lines[] = ['variant_id' => $variant->ulid, 'quantity' => $qty];
                    $amount = bcadd($amount, bcmul((string) $variant->ref_price, (string) $qty, 2), 2);
                }
                if ($lines === []) {
                    continue;
                }
                $channel = $channels[mt_rand(0, $channels->count() - 1)];
                $customer = $customers !== [] && mt_rand(0, 2) === 0 ? $customers[array_rand($customers)] : null;
                $this->orders->create([
                    'channel_id' => $channel->ulid,
                    'warehouse_id' => $warehouse->ulid,
                    'stage' => 'completed',
                    'customer_id' => $customer,
                    'lines' => $lines,
                    'payments' => [['method' => mt_rand(0, 1) ? 'bank_transfer' : 'card', 'amount' => $amount]],
                ]);
                $created++;
            }
        }
        Carbon::setTestNow();

        return $created;
    }

    /** Weekly delivery from the supplier: every SKU back to at least 40 units in the main warehouse. */
    private function restock(Warehouse $warehouse, $variants, int $tenantId): void
    {
        $lines = [];
        foreach ($variants as $variant) {
            $onHand = (int) DB::table('stock_balances')->where('tenant_id', $tenantId)->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $variant->id)->value('on_hand');
            if ($onHand < 40) {
                $lines[] = ['variant_id' => $variant->id, 'quantity' => 40 - $onHand + mt_rand(0, 20), 'unit_cost' => (string) ($variant->cost_price ?: round((float) $variant->ref_price * 0.45))];
            }
        }
        if ($lines !== []) {
            $this->documents->receive($warehouse, $lines, 'Nhập hàng định kỳ hằng tuần', StockReceipt::SOURCE_MANUAL);
        }
    }
}
