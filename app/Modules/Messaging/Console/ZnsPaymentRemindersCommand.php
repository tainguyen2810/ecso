<?php

namespace App\Modules\Messaging\Console;

use App\Modules\Messaging\Models\ZaloAccount;
use App\Modules\Messaging\Zalo\ZaloService;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Zalo ZNS "Nhắc thanh toán" (C6): once a day, own-channel orders still owing money 3 to 30 days after they were
 * placed get the shop's reminder template, once per order.
 */
class ZnsPaymentRemindersCommand extends Command
{
    protected $signature = 'ecso:zalo:payment-reminders {--tenant= : Only this tenant id}';

    protected $description = 'Gửi tin Zalo nhắc thanh toán cho đơn kênh riêng còn nợ quá 3 ngày.';

    public function handle(ZaloService $zalo): int
    {
        $tenantIds = ZaloAccount::query()->withoutGlobalScopes()->where('status', ZaloAccount::CONNECTED)->pluck('tenant_id');
        $tenants = Tenant::query()->whereIn('id', $tenantIds)->where('status', Tenant::STATUS_ACTIVE)->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($tenants as $tenant) {
            $sent = TenantContext::run($tenant, function () use ($zalo) {
                $account = $zalo->account();
                if ($account === null || $zalo->ruleFor($account, 'payment_reminder') === null) {
                    return 0;
                }
                $sent = 0;
                Order::query()->where('marketplace', Order::SOURCE_MANUAL)->whereNotNull('buyer_phone')
                    ->whereNotIn('status', [OrderStatus::CANCELLED, OrderStatus::RETURNED])
                    ->whereRaw('total - paid_amount - refunded_amount > 0')
                    ->whereBetween('placed_at', [now()->subDays(30), now()->subDays(3)])
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('zalo_messages')->whereColumn('zalo_messages.order_id', 'orders.id')
                        ->where('zalo_messages.event', 'payment_reminder')->where('zalo_messages.status', 'sent'))
                    ->limit(500)->get()
                    ->each(function (Order $order) use ($zalo, &$sent) {
                        $sent += $zalo->send($order, 'payment_reminder')?->status === 'sent' ? 1 : 0;
                    });

                return $sent;
            });
            if ($sent > 0) {
                $this->line("{$tenant->name}: {$sent} tin nhắc thanh toán");
            }
        }

        return self::SUCCESS;
    }
}
