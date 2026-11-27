<?php

namespace App\Modules\Sales\Subscriptions;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\CustomerSubscription;
use App\Modules\Sales\Models\CustomerSubscriptionPeriod;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Services\ManualOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gia hạn: a renewal is an ordinary own-channel order with one subscription line naming the subscription, so the
 * money, debt, invoice and reports work as for any sale. SubscriptionService then adds the period.
 */
class RenewalService
{
    public function __construct(private readonly ManualOrderService $orders, private readonly SubscriptionService $subscriptions) {}

    /**
     * @param  array{variant_id?: string|null, start?: string|null, stage?: string, payments?: array<int, array{method: string, amount: string|int|float}>, unit_price?: string|int|float|null, note?: string|null}  $data
     */
    public function renew(CustomerSubscription $sub, array $data): Order
    {
        if ($sub->status === CustomerSubscription::CANCELLED) {
            throw ValidationException::withMessages(['subscription' => 'Thuê bao đã huỷ. Mở lại thuê bao trước khi gia hạn.']);
        }
        $variant = $this->variant($sub, $data['variant_id'] ?? null);
        $line = array_filter([
            'variant_id' => $variant->ulid,
            'quantity' => 1,
            'subscription_id' => $sub->ulid,
            'start' => $data['start'] ?? null,
            'unit_price' => $data['unit_price'] ?? null,
        ], fn ($v) => $v !== null);

        return $this->orders->create([
            'channel_id' => $this->channel($sub)->ulid,
            'customer_id' => $sub->customer->ulid,
            'stage' => $data['stage'] ?? 'completed',
            'promotions_off' => true,
            'note' => $data['note'] ?? "Gia hạn thuê bao {$sub->code}",
            'lines' => [$line],
            'payments' => $data['payments'] ?? [],
        ]);
    }

    /**
     * Tự tạo đơn gia hạn: an order waiting for confirmation, for the plan the customer uses now. It counts only once
     * staff confirm it; RenewalJobs cancels it when the customer lets the subscription run out.
     */
    public function draftRenewal(CustomerSubscription $sub): ?Order
    {
        if ($sub->renewal_order_id !== null || $this->hasPending($sub) || $sub->product_variant_id === null) {
            return null;
        }
        $order = $this->renew($sub, ['stage' => 'pending', 'note' => "Đơn gia hạn tự tạo cho thuê bao {$sub->code}, hết hạn ".$sub->ends_on?->format('d/m/Y')]);
        DB::transaction(function () use ($sub, $order) {
            $fresh = CustomerSubscription::query()->lockForUpdate()->findOrFail($sub->id);
            $fresh->forceFill(['renewal_order_id' => $order->id])->save();
            $this->subscriptions->log($fresh, 'auto_order', "Tự tạo đơn gia hạn {$order->displayCode()} (chờ xác nhận)");
        });

        return $order;
    }

    /** The renewal order the customer never confirmed, cancelled once the grace days are over. */
    public function dropStaleRenewal(CustomerSubscription $sub): bool
    {
        $order = $sub->renewal_order_id ? Order::query()->find($sub->renewal_order_id) : null;
        if ($order === null || $order->status !== OrderStatus::PENDING_CONFIRMATION) {
            return false;
        }
        $this->orders->transition($order, 'cancel', ['reason' => 'Khách không gia hạn thuê bao']);

        return true;
    }

    public function hasPending(CustomerSubscription $sub): bool
    {
        return CustomerSubscriptionPeriod::query()->where('customer_subscription_id', $sub->id)->where('status', CustomerSubscriptionPeriod::PENDING)->exists();
    }

    /** The cycle chosen (a SKU of the same service), the current one by default. */
    private function variant(CustomerSubscription $sub, ?string $ulid): ProductVariant
    {
        $variant = $ulid !== null
            ? ProductVariant::query()->where('ulid', $ulid)->first()
            : ProductVariant::query()->find($sub->product_variant_id);
        if ($variant === null || $variant->product_id !== $sub->product_id) {
            throw ValidationException::withMessages(['variant_id' => 'Chọn một gói của đúng dịch vụ này.']);
        }

        return $variant;
    }

    /** Renewals go to the channel of the last sale of the subscription, or the shop counter. */
    private function channel(CustomerSubscription $sub): SalesChannel
    {
        $last = CustomerSubscriptionPeriod::query()->where('customer_subscription_id', $sub->id)->latest('id')->first();
        $channelId = $last ? Order::query()->whereKey($last->order_id)->value('sales_channel_id') : null;

        return SalesChannel::query()->where('is_active', true)->when($channelId, fn ($q) => $q->whereKey($channelId))->first()
            ?? SalesChannel::query()->where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
