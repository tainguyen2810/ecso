<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /**
     * Every tenant gets one default warehouse so stock can be recorded from day one.
     */
    public function createDefault(): Warehouse
    {
        $warehouse = new Warehouse(['code' => 'MAIN', 'name' => 'Kho chính', 'status' => Warehouse::STATUS_ACTIVE]);
        $warehouse->is_default = true;
        $warehouse->save();

        return $warehouse;
    }

    /**
     * @param  array{code: string, name: string, address?: string|null}  $data
     */
    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            // Serialize concurrent creates per tenant so the plan limit cannot be bypassed.
            DB::table('tenants')->where('id', $this->context->id())->lockForUpdate()->value('id');
            $this->context->entitlements()->assertWithin('limit.warehouses', Warehouse::query()->count());

            $warehouse = Warehouse::create($data + ['status' => Warehouse::STATUS_ACTIVE]);
            if (! Warehouse::query()->where('is_default', true)->exists()) {
                $warehouse->forceFill(['is_default' => true])->save();
            }

            $this->audit->record('warehouse.created', $warehouse, ['code' => $warehouse->code, 'name' => $warehouse->name]);

            return $warehouse;
        });
    }

    /**
     * @param  array{name?: string, address?: string|null, status?: string, is_default?: bool}  $data
     */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data) {
            if (($data['status'] ?? null) === Warehouse::STATUS_INACTIVE) {
                if ($warehouse->is_default) {
                    throw new InvalidStockOperation('Không thể ngừng kho mặc định. Hãy chọn kho mặc định khác trước.');
                }
                if (StockBalance::query()->where('warehouse_id', $warehouse->id)->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0))->exists()) {
                    throw new InvalidStockOperation('Kho còn tồn hoặc đang giữ hàng, không thể ngừng hoạt động.');
                }
            }

            if (! empty($data['is_default'])) {
                if (($data['status'] ?? $warehouse->status) !== Warehouse::STATUS_ACTIVE) {
                    throw new InvalidStockOperation('Kho mặc định phải đang hoạt động.');
                }
                Warehouse::query()->where('id', '!=', $warehouse->id)->update(['is_default' => false]);
                $warehouse->is_default = true;
            }

            $original = $warehouse->only(['name', 'address', 'status']);
            $warehouse->fill(array_intersect_key($data, array_flip(['name', 'address', 'status'])))->save();
            $this->audit->record('warehouse.updated', $warehouse, ['from' => $original, 'to' => $warehouse->only(['name', 'address', 'status', 'is_default'])]);

            return $warehouse;
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        if ($warehouse->is_default) {
            throw new InvalidStockOperation('Không thể xóa kho mặc định.');
        }
        if (StockMovement::query()->where('warehouse_id', $warehouse->id)->exists()) {
            throw new InvalidStockOperation('Kho đã có lịch sử nhập xuất, chỉ có thể ngừng hoạt động.');
        }

        $warehouse->delete();
        $this->audit->record('warehouse.deleted', $warehouse, ['code' => $warehouse->code]);
    }
}
