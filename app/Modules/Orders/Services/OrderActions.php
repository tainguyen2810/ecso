<?php

namespace App\Modules\Orders\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderNote;
use App\Modules\Orders\Support\Tracking;
use App\Modules\Sales\Services\ManualOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * User actions on orders. Status-changing actions go to the marketplace first (it owns the order); our copy is
 * then re-read from its API. If the marketplace does not offer the action, UnsupportedCapability is returned.
 */
class OrderActions
{
    /** action => statuses where it makes sense */
    public const ALLOWED_FROM = [
        'confirm' => [OrderStatus::PENDING_CONFIRMATION],
        'pack' => [OrderStatus::READY_TO_PACK],
        'handover' => [OrderStatus::PACKED],
        'cancel' => [OrderStatus::UNPAID, OrderStatus::PENDING_CONFIRMATION, OrderStatus::READY_TO_PACK, OrderStatus::PACKED],
    ];

    /** action => status the order is in after it (for actions kept in ECSO) */
    public const RESULTS = [
        'confirm' => OrderStatus::READY_TO_PACK,
        'pack' => OrderStatus::PACKED,
        'handover' => OrderStatus::HANDED_OVER,
        'cancel' => OrderStatus::CANCELLED,
    ];

    public function __construct(
        private readonly OrderIngestor $ingestor,
        private readonly ConnectorRegistry $connectors,
        private readonly OrderSync $sync,
        private readonly AuditLogger $audit,
        private readonly ManualOrderService $manual,
        private readonly ShipmentSync $shipments,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function perform(Order $order, string $action, array $params = []): Order
    {
        // Orders of the seller's own channels are processed in ECSO, not on a marketplace.
        if ($order->isManual()) {
            return $this->manual->transition($order, $action, $params);
        }
        if (! isset(self::ALLOWED_FROM[$action])) {
            throw ValidationException::withMessages(['action' => 'Thao tác không hợp lệ.']);
        }
        if (! in_array($order->status, self::ALLOWED_FROM[$action], true)) {
            throw ValidationException::withMessages(['action' => 'Đơn đang ở trạng thái không cho phép thao tác này.']);
        }

        $account = $order->account;
        if ($action === 'handover' && $order->marketplace === WooCommerceConnector::MARKETPLACE) {
            Tracking::assertUnique($order, Tracking::normalize($params['tracking_number'] ?? null)); // before anything moves
        }
        // One-way store (the seller chose "chỉ website → ECSO"): the action stays in ECSO, the store is not written.
        if ($account->marketplace === WooCommerceConnector::MARKETPLACE && ! $account->syncs('push_status')) {
            $order = $this->ingestor->applyLocal($order, self::RESULTS[$action], 'user', Auth::id(), $params['reason'] ?? null);
            $this->keepShipping($order, $action, $params);
            $this->audit->record("order.{$action}", $order, ['order' => $order->external_order_id, 'local' => true]);

            return $order;
        }
        $connector = $this->connectors->for($account->marketplace);
        $capability = Capability::ORDER_ACTIONS[$action];
        $state = $connector->capabilities()[$capability] ?? Capability::UNKNOWN;
        if ($state !== Capability::SUPPORTED) {
            throw new UnsupportedCapability($account->marketplace, $capability, $state);
        }

        try {
            $connector->performOrderAction($account, $order->external_order_id, $action, $params);
        } catch (ConnectorException $e) {
            throw ValidationException::withMessages(['action' => $e->getMessage()]);
        }

        $result = $this->sync->syncOne($account, $order->external_order_id, 'user', null, Auth::id());
        $order = $result['order'] ?? $order;
        $this->keepShipping($order, $action, $params);
        $this->audit->record("order.{$action}", $order, ['order' => $order->external_order_id]);

        return $order;
    }

    /**
     * Website orders are shipped by the shop's own carrier: the carrier and tracking number typed at handover stay on
     * the order (the store has no such fields, so later syncs keep them). Marketplace orders get theirs from the
     * marketplace.
     *
     * @param  array<string, mixed>  $params
     */
    private function keepShipping(Order $order, string $action, array $params): void
    {
        if ($action !== 'handover' || $order->marketplace !== WooCommerceConnector::MARKETPLACE) {
            return;
        }
        $tracking = Tracking::normalize($params['tracking_number'] ?? null);
        $carrier = filled($params['carrier'] ?? null) ? trim($params['carrier']) : null;
        if ($tracking === null && $carrier === null) {
            return;
        }
        DB::transaction(function () use ($order, $tracking, $carrier) {
            $order->forceFill(['tracking_number' => $tracking ?? $order->tracking_number, 'shipping_carrier' => $carrier ?? $order->shipping_carrier])->save();
            $this->shipments->apply($order);
        });
    }

    /**
     * Runs an action on many orders; one failure does not stop the others.
     *
     * @param  iterable<Order>  $orders
     * @return array<int, array{id: string, ok: bool, message: string|null}>
     */
    public function bulk(iterable $orders, string $action, array $params = []): array
    {
        $results = [];
        foreach ($orders as $order) {
            try {
                $this->perform($order, $action, $params);
                $results[] = ['id' => $order->ulid, 'ok' => true, 'message' => null];
            } catch (ValidationException $e) {
                $results[] = ['id' => $order->ulid, 'ok' => false, 'message' => collect($e->errors())->flatten()->first()];
            } catch (UnsupportedCapability $e) {
                $results[] = ['id' => $order->ulid, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function assign(Order $order, ?User $user): Order
    {
        $order->forceFill(['assigned_to' => $user?->id])->save();
        $this->audit->record('order.assigned', $order, ['order' => $order->external_order_id, 'user' => $user?->email]);

        return $order;
    }

    public function addNote(Order $order, string $body): OrderNote
    {
        return $order->notes()->create(['user_id' => Auth::id(), 'body' => $body]);
    }
}
