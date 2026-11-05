<?php

namespace App\Modules\Orders\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use App\Modules\Orders\Support\Tracking;
use App\Modules\Sales\Services\ManualOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the shop does by hand on a shipment (shipping round S3): open one on an order it ships itself, correct it, and
 * record what the carrier reported. Every step that is also an order step (picked up, delivered, failed, redelivered)
 * goes through the order's own action, so stock, returns and revenue follow the same rules as the order's buttons;
 * the shipment then follows the order (ShipmentSync). Marketplace orders are shipped by the marketplace: read only.
 */
class ShipmentService
{
    /** Shipment action => [shipment statuses it starts from, order action it runs (null = shipment only)]. */
    public const ACTIONS = [
        'pick_up' => [[ShipmentStatus::PENDING, ShipmentStatus::AWAITING_PICKUP], 'handover'],
        'deliver' => [[ShipmentStatus::PICKED_UP, ShipmentStatus::IN_TRANSIT, ShipmentStatus::OUT_FOR_DELIVERY, ShipmentStatus::FAILED, ShipmentStatus::REDELIVERING], 'deliver'],
        'fail' => [[ShipmentStatus::PICKED_UP, ShipmentStatus::IN_TRANSIT, ShipmentStatus::OUT_FOR_DELIVERY, ShipmentStatus::REDELIVERING], 'fail_delivery'],
        'redeliver' => [[ShipmentStatus::FAILED], 'redeliver'],
        // Chuyển hoàn: the carrier sends the parcel back. The order stays "Giao thất bại" with its return on the way;
        // receiving and inspecting that return closes the order as returned.
        'return' => [[ShipmentStatus::FAILED], null],
        'cancel' => [[ShipmentStatus::PENDING, ShipmentStatus::AWAITING_PICKUP], null],
    ];

    public const LABELS = [
        'pick_up' => 'Đã lấy hàng', 'deliver' => 'Đã giao thành công', 'fail' => 'Giao thất bại', 'redeliver' => 'Giao lại',
        'return' => 'Chuyển hoàn', 'cancel' => 'Hủy vận đơn',
    ];

    public function __construct(
        private readonly ShipmentSync $sync,
        private readonly ManualOrderService $orders,
        private readonly AuditLogger $audit,
    ) {}

    /** Orders whose parcels the shop sends itself (own sales, own website). */
    public static function shipsItself(Order $order): bool
    {
        return $order->ownsCustomerData();
    }

    /**
     * The actions the shop can take on a shipment now, with why not when it cannot.
     *
     * @return array<string, array{label: string, allowed: bool, reason: string|null}>
     */
    public function actions(Shipment $shipment, Order $order): array
    {
        $result = [];
        foreach (self::ACTIONS as $action => [$from, $orderAction]) {
            if (! in_array($shipment->status, $from, true)) {
                continue;
            }
            $reason = match (true) {
                ! self::shipsItself($order) => 'Đơn sàn: trạng thái giao do sàn cập nhật.',
                $orderAction !== null && ! $order->isManual() => 'Đơn website: trạng thái giao theo đơn trên website.',
                $orderAction !== null && ! $this->orderAllows($order, $orderAction) => 'Đơn đang ở trạng thái không cho phép thao tác này.',
                default => null,
            };
            $result[$action] = ['label' => self::LABELS[$action], 'allowed' => $reason === null, 'reason' => $reason];
        }

        return $result;
    }

    /**
     * Opens a shipment on an order the shop ships itself: a tracking number from the carrier's app, or its own shipper.
     *
     * @param  array{mode: string, carrier?: string|null, tracking_number?: string|null, packages?: int|null, weight_g?: int|null, fee_estimated?: string|float|int|null, payer?: string|null}  $data
     */
    public function create(Order $order, array $data): Shipment
    {
        if (! self::shipsItself($order)) {
            throw ValidationException::withMessages(['order' => 'Đơn sàn do sàn tạo vận đơn, không tạo ở đây.']);
        }

        return DB::transaction(function () use ($order, $data) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::RETURNED, OrderStatus::UNPAID], true)) {
                throw ValidationException::withMessages(['order' => 'Đơn đã hủy, đã hoàn hoặc chưa thanh toán: không tạo vận đơn.']);
            }
            if ($this->sync->main($order) !== null) {
                throw ValidationException::withMessages(['order' => 'Đơn đã có vận đơn. Hủy vận đơn cũ trước khi tạo vận đơn mới.']);
            }

            $self = $data['mode'] === Shipment::SOURCE_SELF;
            $tracking = Tracking::normalize($data['tracking_number'] ?? null);
            if (! $self && $tracking === null) {
                throw ValidationException::withMessages(['tracking_number' => 'Nhập mã vận đơn của hãng.']);
            }
            Tracking::assertUnique($order, $tracking);
            $carrier = $self ? (filled($data['carrier'] ?? null) ? trim((string) $data['carrier']) : 'Tự giao') : trim((string) ($data['carrier'] ?? ''));
            if ($carrier === '') {
                throw ValidationException::withMessages(['carrier' => 'Chọn hãng vận chuyển.']);
            }

            $order->forceFill(['shipping_carrier' => $carrier, 'tracking_number' => $tracking])->save();
            $shipment = Shipment::query()->create([
                'order_id' => $order->id,
                'source' => $self ? Shipment::SOURCE_SELF : Shipment::SOURCE_MANUAL,
                'status' => ShipmentStatus::AWAITING_PICKUP,
                'warehouse_id' => $order->warehouse_id,
                'ship_by_at' => $order->ship_by_at,
                'packages' => max(1, (int) ($data['packages'] ?? 1)),
                'weight_g' => $data['weight_g'] ?? null,
                'fee_estimated' => $data['fee_estimated'] ?? null,
                'fee_status' => isset($data['fee_estimated']) ? 'estimated' : 'unknown',
                'payer' => $data['payer'] ?? 'shop',
                'created_by' => Auth::id(),
            ]);
            $this->sync->event($shipment, ShipmentStatus::AWAITING_PICKUP, now(), $self ? 'Tạo vận đơn tự giao' : 'Tạo vận đơn', 'created', ShipmentEvent::SOURCE_USER);
            $shipment = $this->sync->apply($order) ?? $shipment;
            $this->audit->record('shipment.created', $order, ['order' => $order->external_order_id, 'carrier' => $carrier, 'tracking_number' => $tracking]);

            return $shipment->refresh();
        });
    }

    /**
     * Corrects a shipment: carrier and tracking number (on orders the shop ships itself, kept on the order too),
     * parcels, weight and the estimated fee (display only).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Shipment $shipment, array $data): Shipment
    {
        return DB::transaction(function () use ($shipment, $data) {
            $order = Order::query()->whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $before = $shipment->only(['carrier_name', 'tracking_number', 'packages', 'weight_g', 'fee_estimated']);

            if (array_key_exists('carrier', $data) || array_key_exists('tracking_number', $data)) {
                if (! self::shipsItself($order)) {
                    throw ValidationException::withMessages(['tracking_number' => 'Mã vận đơn của đơn sàn do sàn cấp, không sửa ở đây.']);
                }
                if ($this->sync->main($order)?->id !== $shipment->id) {
                    throw ValidationException::withMessages(['tracking_number' => 'Chỉ sửa được mã của vận đơn đang dùng.']);
                }
                if (array_key_exists('tracking_number', $data)) {
                    $order->tracking_number = Tracking::normalize($data['tracking_number']);
                    Tracking::assertUnique($order, $order->tracking_number);
                }
                if (array_key_exists('carrier', $data) && filled($data['carrier'])) {
                    $order->shipping_carrier = trim((string) $data['carrier']);
                }
                $order->save();
            }
            foreach (['packages', 'weight_g'] as $field) {
                if (array_key_exists($field, $data)) {
                    $shipment->{$field} = $data[$field];
                }
            }
            if (array_key_exists('fee_estimated', $data)) {
                $shipment->fee_estimated = $data['fee_estimated'];
                if ($shipment->fee_status !== 'confirmed') {
                    $shipment->fee_status = $data['fee_estimated'] !== null ? 'estimated' : 'unknown';
                }
            }
            $shipment->save();
            $this->sync->apply($order);
            $shipment->refresh();
            $this->audit->record('shipment.updated', $order, ['order' => $order->external_order_id, 'before' => $before, 'after' => $shipment->only(array_keys($before))]);

            return $shipment;
        });
    }

    /** Records what the carrier reported, through the order's own action where there is one. */
    public function act(Shipment $shipment, string $action, ?string $reason = null): Shipment
    {
        if (! isset(self::ACTIONS[$action])) {
            throw ValidationException::withMessages(['action' => 'Thao tác không hợp lệ.']);
        }
        $order = Order::query()->findOrFail($shipment->order_id);
        $allowed = $this->actions($shipment, $order)[$action] ?? null;
        if ($allowed === null || ! $allowed['allowed']) {
            throw ValidationException::withMessages(['action' => $allowed['reason'] ?? 'Vận đơn đang ở trạng thái không cho phép thao tác này.']);
        }
        if ($this->sync->main($order)?->id !== $shipment->id) {
            throw ValidationException::withMessages(['action' => 'Vận đơn này không còn là vận đơn đang dùng của đơn.']);
        }

        [, $orderAction] = self::ACTIONS[$action];
        if ($orderAction !== null) {
            $this->orders->transition($order, $orderAction, array_filter(['reason' => $reason]));

            return $shipment->refresh();
        }

        return DB::transaction(function () use ($shipment, $order, $action, $reason) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $target = $action === 'return' ? ShipmentStatus::RETURNING : ShipmentStatus::CANCELLED;
            if (! ShipmentStatus::canMove($shipment->status, $target)) {
                throw ValidationException::withMessages(['action' => 'Vận đơn đang ở trạng thái không cho phép thao tác này.']);
            }
            $shipment->status = $target;
            $shipment->last_event_at = now();
            if ($target === ShipmentStatus::CANCELLED) {
                $shipment->cancelled_at = now();
                $shipment->cod_status = Shipment::COD_NONE;
                // The order no longer carries this number, so a new shipment (or the same number, retyped) can be made.
                $order->forceFill(['tracking_number' => null])->save();
            } else {
                $shipment->cod_status = bccomp((string) $shipment->cod_amount, '0', 2) > 0 ? Shipment::COD_RETURNED : Shipment::COD_NONE;
            }
            $shipment->save();
            $this->sync->event($shipment, $target, now(), $reason, "user:{$target}:{$shipment->attempts}", ShipmentEvent::SOURCE_USER);
            $this->audit->record("shipment.{$action}", $order, ['order' => $order->external_order_id, 'tracking_number' => $shipment->tracking_number, 'reason' => $reason]);

            return $shipment;
        });
    }

    private function orderAllows(Order $order, string $orderAction): bool
    {
        $from = ManualOrderService::ACTIONS[$orderAction][0] ?? [];

        return in_array($order->status, $from, true);
    }
}
