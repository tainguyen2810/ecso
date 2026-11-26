<?php

namespace App\Modules\Sales\Subscriptions;

use App\Modules\Messaging\Zalo\ZaloService;
use App\Modules\Sales\Models\CustomerSubscription;
use Illuminate\Support\Facades\DB;

/** "Nhắc gia hạn": the Zalo reminder to the customer and its line in the subscription's log. */
class Reminder
{
    public function __construct(private readonly ZaloService $zalo, private readonly SubscriptionService $subscriptions) {}

    /** One reminder per end date and day; a renewed subscription has a new end date, so its reminders start again. */
    public function send(CustomerSubscription $sub, int $left, bool $useZalo, bool $force = false): bool
    {
        $key = $sub->ends_on->toDateString().':'.$left;
        if (! $force && in_array($key, $sub->reminded ?? [], true)) {
            return false;
        }
        $sent = null;
        $phone = (string) ($sub->customer->phone ?? '');
        if ($useZalo && $phone !== '') {
            $sent = $this->zalo->sendReminder($phone, [
                'customer_name' => (string) ($sub->customer->name ?? 'Quý khách'),
                'service_name' => $sub->product->name,
                'service_label' => (string) ($sub->label ?? ''),
                'expiry_date' => $sub->ends_on->format('d/m/Y'),
                'renew_price' => number_format((float) ($sub->variant->ref_price ?? 0), 0, ',', '.'),
                'order_code' => $sub->code,
            ], "{$sub->ulid}-{$key}");
        }
        DB::transaction(function () use ($sub, $key, $left, $sent) {
            $fresh = CustomerSubscription::query()->lockForUpdate()->findOrFail($sub->id);
            $fresh->forceFill(['reminded' => array_values(array_slice([...($fresh->reminded ?? []), $key], -12))])->save();
            $when = $left > 0 ? "còn {$left} ngày" : ($left === 0 ? 'hết hạn hôm nay' : 'đã hết hạn '.(-$left).' ngày');
            $how = match (true) {
                $sent?->status === 'sent' => 'đã gửi Zalo cho khách',
                $sent !== null => 'gửi Zalo lỗi: '.($sent->error ?? ''),
                default => 'chưa gửi cho khách (chưa chọn mẫu Zalo "Nhắc gia hạn" hoặc khách không có số di động)',
            };
            $this->subscriptions->log($fresh, 'reminded', "Nhắc gia hạn ({$when}): {$how}");
        });

        return true;
    }
}
