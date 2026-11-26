<?php

namespace App\Modules\Sales\Subscriptions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Sales\Models\CustomerSubscription;
use App\Modules\Sales\Models\CustomerSubscriptionEvent;
use App\Modules\Sales\Models\CustomerSubscriptionPeriod;
use App\Shared\Support\ReportRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Keeps subscriptions in step with the orders that sell them. Each subscription line of an own-channel order is one
 * period: pending while the order waits for confirmation, active once confirmed (or completed), void once cancelled.
 * A subscription's dates are always those of its active periods, so editing or cancelling an order puts them right.
 *
 * Must run inside the transaction that changed the order.
 */
class SubscriptionService
{
    public function syncOrder(Order $order): void
    {
        /** @var Collection<int, OrderItem> $items */
        $items = $order->items()->whereNotNull('subscription')->whereNotNull('service_variant_id')->orderBy('id')->get();
        $old = CustomerSubscriptionPeriod::query()->where('order_id', $order->id)->lockForUpdate()->get()->keyBy('line_no');
        if ($items->isEmpty() && $old->isEmpty()) {
            return;
        }
        if ($items->isNotEmpty() && $order->customer_id === null) {
            throw ValidationException::withMessages(['customer_id' => 'Chọn khách hàng để bán thuê bao: hệ thống cần biết ai dùng dịch vụ để nhắc gia hạn.']);
        }

        $status = match (true) {
            in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::RETURNED], true) => CustomerSubscriptionPeriod::VOID,
            $order->status === OrderStatus::PENDING_CONFIRMATION => CustomerSubscriptionPeriod::PENDING,
            default => CustomerSubscriptionPeriod::ACTIVE,
        };

        // The order's periods are written again from its lines; the subscriptions they touched get their dates back.
        $touched = $old->pluck('customer_subscription_id')->map(fn ($id) => (int) $id)->all();
        CustomerSubscriptionPeriod::query()->where('order_id', $order->id)->delete();
        foreach (array_unique($touched) as $id) {
            $this->refreshDates(CustomerSubscription::query()->lockForUpdate()->findOrFail($id));
        }

        $placed = Carbon::parse(($order->placed_at ?? now())->copy()->timezone(ReportRange::timezone())->toDateString());
        foreach ($items->values() as $index => $item) {
            $lineNo = $index + 1;
            $meta = (array) $item->subscription;
            $previous = $old->get($lineNo);
            $variant = ProductVariant::query()->withTrashed()->with(['product' => fn ($q) => $q->withTrashed()])->findOrFail($item->service_variant_id);
            $product = $variant->product;
            $months = max(1, (int) ($meta['months'] ?? $variant->period_months ?? 1));

            $sub = $this->subscriptionFor($order, $product, $meta, $previous);
            if ($status === CustomerSubscriptionPeriod::VOID && $previous === null) {
                continue; // cancelled before it ever counted
            }

            $start = match (true) {
                ! empty($meta['start']) => Carbon::parse($meta['start'])->startOfDay(),
                $previous !== null => $previous->starts_on->copy(),
                default => $this->nextStart($sub, $placed, $product->subscriptionRules()['grace_days']),
            };
            $end = $start->copy()->addMonthsNoOverflow($months)->subDay();
            $amount = bcsub(bcmul((string) $item->unit_price, (string) $item->quantity, 2), (string) $item->discount, 2);

            CustomerSubscriptionPeriod::query()->create([
                'customer_subscription_id' => $sub->id,
                'order_id' => $order->id,
                'line_no' => $lineNo,
                'product_variant_id' => $variant->id,
                'months' => $months,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'amount' => $amount,
                'status' => $status,
            ]);

            if ($status !== CustomerSubscriptionPeriod::VOID) {
                $sub->product_variant_id = $variant->id;
            }
            if (array_key_exists('label', $meta) && filled($meta['label'])) {
                $sub->label = mb_substr(trim((string) $meta['label']), 0, 150);
            }
            if ($sub->renewal_order_id === $order->id && $status !== CustomerSubscriptionPeriod::PENDING) {
                $sub->renewal_order_id = null; // the renewal order was confirmed or cancelled: nothing left waiting
            }
            $this->refreshDates($sub);

            $range = $start->format('d/m/Y').' – '.$end->format('d/m/Y');
            if ($status === CustomerSubscriptionPeriod::ACTIVE && $previous?->status !== CustomerSubscriptionPeriod::ACTIVE) {
                $this->log($sub, 'renewed', "Thêm {$months} tháng ({$range}) · đơn {$order->external_order_id}");
            } elseif ($status === CustomerSubscriptionPeriod::VOID && $previous?->status !== CustomerSubscriptionPeriod::VOID) {
                $this->log($sub, 'period_void', "Đơn {$order->external_order_id} đã huỷ: bỏ kỳ {$range}");
            } elseif ($status === CustomerSubscriptionPeriod::PENDING && $previous === null) {
                $this->log($sub, 'renewal_order', "Đơn {$order->external_order_id} chờ xác nhận: {$months} tháng ({$range})");
            }
        }
    }

    /**
     * The subscription a line renews: the one it names, the one it renewed before, or the customer's subscription to
     * the same service (same label when one is given); a new one otherwise.
     *
     * @param  array<string, mixed>  $meta
     */
    private function subscriptionFor(Order $order, Product $product, array $meta, ?CustomerSubscriptionPeriod $previous): CustomerSubscription
    {
        if (! empty($meta['subscription_id'])) {
            $sub = CustomerSubscription::query()->where('ulid', $meta['subscription_id'])->lockForUpdate()->first();
            if ($sub === null || $sub->customer_id !== (int) $order->customer_id || $sub->product_id !== $product->id) {
                throw ValidationException::withMessages(['lines' => 'Thuê bao cần gia hạn không thuộc khách hoặc dịch vụ này.']);
            }

            return $sub;
        }
        if ($previous !== null) {
            $sub = CustomerSubscription::query()->lockForUpdate()->find($previous->customer_subscription_id);
            if ($sub !== null && $sub->customer_id === (int) $order->customer_id && $sub->product_id === $product->id) {
                return $sub;
            }
        }
        $label = filled($meta['label'] ?? null) ? trim((string) $meta['label']) : null;
        $existing = CustomerSubscription::query()->where('customer_id', $order->customer_id)->where('product_id', $product->id)
            ->where('status', '!=', CustomerSubscription::CANCELLED)
            ->when($label !== null, fn ($q) => $q->where(fn ($w) => $w->where('label', $label)->orWhereNull('label')))
            ->orderByRaw('label is null')->orderByDesc('ends_on')->lockForUpdate()->first();
        if ($existing !== null) {
            return $existing;
        }

        $sub = CustomerSubscription::query()->create([
            'code' => DocumentNumber::next('TB'),
            'customer_id' => $order->customer_id,
            'product_id' => $product->id,
            'label' => $label,
            'status' => CustomerSubscription::ACTIVE,
            'assigned_to' => $order->assigned_to ?? Auth::id(),
            'remind' => $product->subscriptionRules()['remind'],
        ]);
        $this->log($sub, 'created', "Bán từ đơn {$order->external_order_id}");

        return $sub;
    }

    /**
     * Renewing continues from the day after the old end, so the customer loses no day. A customer who comes back
     * long after the end (past the grace days) starts again from the day of the order.
     */
    public function nextStart(?CustomerSubscription $sub, Carbon $placed, int $graceDays): Carbon
    {
        if ($sub === null || $sub->ends_on === null) {
            return $placed->copy();
        }
        $next = $sub->ends_on->copy()->addDay()->startOfDay();
        if ($next->greaterThanOrEqualTo($placed) || $next->diffInDays($placed) <= $graceDays) {
            return $next;
        }

        return $placed->copy();
    }

    /** Dates = first start and last end of the active periods (reminders are kept per end date, see Reminders). */
    public function refreshDates(CustomerSubscription $sub): void
    {
        $active = CustomerSubscriptionPeriod::query()->where('customer_subscription_id', $sub->id)->where('status', CustomerSubscriptionPeriod::ACTIVE);
        $sub->starts_on = (clone $active)->min('starts_on');
        $sub->ends_on = (clone $active)->max('ends_on');
        $sub->save();
    }

    /** Today on the shop's clock, as a date. */
    public static function today(): Carbon
    {
        return Carbon::parse(ReportRange::now()->toDateString());
    }

    public function log(CustomerSubscription $sub, string $kind, ?string $note = null): void
    {
        CustomerSubscriptionEvent::query()->create(['customer_subscription_id' => $sub->id, 'kind' => $kind, 'note' => $note === null ? null : mb_substr($note, 0, 500), 'user_id' => Auth::id()]);
    }
}
