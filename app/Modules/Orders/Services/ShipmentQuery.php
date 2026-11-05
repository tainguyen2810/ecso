<?php

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Shipment;
use App\Shared\Support\ReportRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/** Filters of the "Vận chuyển & giao hàng" list, shared by the list and its export. */
class ShipmentQuery
{
    /** Tabs of the page: shipment statuses grouped the way the shop works on them. */
    public const TABS = [
        'awaiting' => [ShipmentStatus::PENDING, ShipmentStatus::AWAITING_PICKUP],
        'delivering' => [ShipmentStatus::PICKED_UP, ShipmentStatus::IN_TRANSIT, ShipmentStatus::OUT_FOR_DELIVERY, ShipmentStatus::REDELIVERING],
        'failed' => [ShipmentStatus::FAILED],
        'returning' => [ShipmentStatus::RETURNING],
        'delivered' => [ShipmentStatus::DELIVERED],
        'returned' => [ShipmentStatus::RETURNED],
        'cancelled' => [ShipmentStatus::CANCELLED],
    ];

    /** A parcel still on its way this many days after pickup is late. */
    public const LATE_DAYS = 5;

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'tab' => ['nullable', Rule::in(array_keys(self::TABS))],
            'status' => ['nullable', Rule::in(ShipmentStatus::ALL)],
            'carrier' => ['nullable', 'string', 'max:20'],
            'cod' => ['nullable', Rule::in(['with', 'without', Shipment::COD_PENDING, Shipment::COD_COLLECTED, Shipment::COD_REMITTED, Shipment::COD_RETURNED])],
            'warehouse_id' => ['nullable', 'string', 'max:26'],
            'channel' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'late' => ['nullable', 'boolean'],
            'order_id' => ['nullable', 'string', 'max:26'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** Shipments of orders the member may see. @return Builder<Shipment> */
    public static function visible(): Builder
    {
        return Shipment::query()->whereHas('order', fn (Builder $o) => OrderQuery::visible($o));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Shipment>
     */
    public static function build(array $filters): Builder
    {
        return self::visible()
            ->when($filters['tab'] ?? null, fn ($q, $tab) => $q->whereIn('status', self::TABS[$tab]))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['carrier'] ?? null, fn ($q, $c) => $c === 'other' ? $q->whereNull('carrier') : $q->where('carrier', $c))
            ->when($filters['cod'] ?? null, fn ($q, $c) => match ($c) {
                'with' => $q->where('cod_amount', '>', 0),
                'without' => $q->where('cod_amount', '<=', 0),
                default => $q->where('cod_status', $c),
            })
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->whereIn('warehouse_id', Warehouse::query()->where('ulid', $id)->select('id')))
            ->when($filters['channel'] ?? null, fn ($q, $ch) => $q->whereHas('order', fn ($o) => $o->where('marketplace', $ch)))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('picked_up_at', '>=', ReportRange::date($d)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('picked_up_at', '<=', ReportRange::date($d)->endOfDay()->utc()))
            ->when($filters['late'] ?? false, fn ($q) => self::late($q))
            ->when($filters['order_id'] ?? null, fn ($q, $id) => $q->whereHas('order', fn ($o) => $o->where('ulid', $id)))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('tracking_number', 'like', "%{$term}%")
                ->orWhereHas('order', fn ($o) => $o->where('external_order_id', 'like', "%{$term}%"))));
    }

    /**
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    public static function late(Builder $query): Builder
    {
        return $query->whereIn('status', self::TABS['delivering'])->where('picked_up_at', '<', now()->subDays(self::LATE_DAYS));
    }
}
