<?php

namespace App\Modules\Access\Support;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the current member may see and touch beyond the permission gates (audit round C):
 *  - cost prices, stock value and receipt unit costs need "products.cost.view";
 *  - a member with warehouses assigned works only in those (none assigned = every warehouse).
 * Outside a member's request (console, queue, system flows) everything is allowed.
 */
class DataAccess
{
    public const COST = 'products.cost.view';

    /** @var array<int, array<int, int>|null> by membership id */
    private array $warehouses = [];

    public function __construct(private readonly TenantContext $context) {}

    public function seesCost(): bool
    {
        return ! $this->context->has() || $this->context->membership() === null || $this->context->can(self::COST);
    }

    /** The value, or null when the member may not see costs. */
    public function cost(mixed $value): mixed
    {
        return $this->seesCost() ? $value : null;
    }

    /**
     * Warehouse ids the member is limited to, or null when not limited.
     *
     * @return array<int, int>|null
     */
    public function warehouseIds(): ?array
    {
        $membership = $this->context->has() ? $this->context->membership() : null;
        if ($membership === null) {
            return null;
        }
        if (! array_key_exists($membership->id, $this->warehouses)) {
            $ids = DB::table('member_warehouses')->where('tenant_user_id', $membership->id)->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();
            $this->warehouses[$membership->id] = $ids === [] ? null : $ids;
        }

        return $this->warehouses[$membership->id];
    }

    public function allowsWarehouse(Warehouse|int|null $warehouse): bool
    {
        $ids = $this->warehouseIds();
        if ($ids === null || $warehouse === null) {
            return true;
        }

        return in_array((int) ($warehouse instanceof Warehouse ? $warehouse->id : $warehouse), $ids, true);
    }

    /** Refuses a document in a warehouse the member is not assigned to. */
    public function assertWarehouse(Warehouse|int|null $warehouse, string $field = 'warehouse_id'): void
    {
        if (! $this->allowsWarehouse($warehouse)) {
            throw ValidationException::withMessages([$field => 'Bạn không được phân công thao tác ở kho này.']);
        }
    }
}
