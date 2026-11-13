<?php

namespace App\Modules\Messaging\Zalo;

use App\Modules\Messaging\Jobs\SendZnsJob;
use App\Modules\Messaging\Models\ZaloAccount;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\SalesChannel;

/**
 * Watches own-channel orders with a phone number (counter sales left out): when one is confirmed, handed to the
 * carrier or delivered and the shop chose a ZNS template for that moment, the message goes out in the background.
 */
class QueueOrderZns
{
    private const EVENTS = [
        OrderStatus::READY_TO_PACK => 'confirmed',
        OrderStatus::HANDED_OVER => 'shipped',
        OrderStatus::IN_TRANSIT => 'shipped',
        OrderStatus::DELIVERED => 'delivered',
        OrderStatus::COMPLETED => 'delivered',
    ];

    public function handle(Order $order): void
    {
        $event = self::EVENTS[$order->status] ?? null;
        if ($event === null || ! $order->isManual() || blank($order->buyer_phone) || (! $order->wasRecentlyCreated && ! $order->wasChanged('status'))) {
            return;
        }
        $rules = ZaloAccount::query()->withoutGlobalScopes()->where('tenant_id', $order->tenant_id)->where('status', '!=', 'disconnected')->value('rules');
        $rules = is_string($rules) ? json_decode($rules, true) : $rules;
        if (! is_array($rules) || empty($rules[$event]['enabled'])) {
            return;
        }
        $channelType = $order->sales_channel_id ? SalesChannel::query()->withoutGlobalScopes()->whereKey($order->sales_channel_id)->value('type') : null;
        if ($channelType === 'pos') {
            return;
        }
        SendZnsJob::dispatch($order->id, $event)->afterCommit();
    }
}
