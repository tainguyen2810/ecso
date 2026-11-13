<?php

namespace App\Modules\Invoicing\Listeners;

use App\Modules\Invoicing\Jobs\IssueEInvoiceJob;
use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;

/**
 * Watches own-channel orders: when one becomes fully paid or completed and the shop chose that moment for
 * "Tự xuất hóa đơn", the invoice is issued in the background after the change is saved.
 */
class QueueAutoEInvoice
{
    public function handle(Order $order): void
    {
        if (! $order->isManual()) {
            return;
        }
        $trigger = match (true) {
            $order->wasChanged('payment_status') && $order->payment_status === Order::PAYMENT_PAID => 'paid',
            $order->wasChanged('status') && $order->status === OrderStatus::COMPLETED => 'completed',
            default => null,
        };
        if ($trigger === null) {
            return;
        }
        $mode = EInvoiceAccount::query()->withoutGlobalScopes()->where('tenant_id', $order->tenant_id)->where('status', EInvoiceAccount::CONNECTED)->value('issue_mode');
        if ($mode === $trigger) {
            IssueEInvoiceJob::dispatch($order->id, $trigger)->afterCommit();
        }
    }
}
