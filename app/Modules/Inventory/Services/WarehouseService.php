<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
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
     * The tenant's warehouse for defective goods, created the first time damaged returns come in.
     */
    public function defective(): Warehouse
    {
        return DB::transaction(function () {
            DB::table('tenants')->where('id', $this->context->id())->lockForUpdate()->value('id');
            $warehouse = Warehouse::query()->where('purpose', Warehouse::PURPOSE_DEFECTIVE)->where('status', Warehouse::STATUS_ACTIVE)->orderBy('id')->first();
            if ($warehouse) {
                return $warehouse;
            }

            $code = 'HANGLOI';
            for ($i = 2; Warehouse::withTrashed()->where('code', $code)->exists(); $i++) {
                $code = 'HANGLOI'.$i;
            }
            $warehouse = new Warehouse(['code' => $code, 'name' => 'Kho hàng lỗi', 'status' => Warehouse::STATUS_ACTIVE]);
            $warehouse->purpose = Warehouse::PURPOSE_DEFECTIVE;
            $warehouse->save();
            $this->audit->record('warehouse.created', $warehouse, ['code' => $warehouse->code, 'name' => $warehouse->name, 'purpose' => Warehouse::PURPOSE_DEFECTIVE]);

            return $warehouse;
        });
    }

    /**
     * @param  array{code: string, name: string, address?: string|null}  $data
     */
    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            // Serialize concurrent creates per tenant so the plan limit cannot be bypassed.
            DB::table('tenants')->where('id', $this->context->id())->lockForUpdate()->value('id');
            $this->context->entitlements()->assertWithin('limit.warehouses', Warehouse::query()->where('purpose', Warehouse::PURPOSE_SALE)->count());

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
                $this->assertNothingOpen($warehouse);
            }

            if (! empty($data['is_default'])) {
                if ($warehouse->purpose === Warehouse::PURPOSE_DEFECTIVE) {
                    throw new InvalidStockOperation('Kho hàng lỗi không thể là kho mặc định.');
                }
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

    /**
     * Work still pointing at the warehouse would fail once it is inactive ("kho ngừng hoạt động"): open purchase
     * orders, transfers on the way, stocktakes not decided, shops publishing its stock.
     */
    private function assertNothingOpen(Warehouse $warehouse): void
    {
        $tenant = $warehouse->tenant_id;
        $open = array_filter([
            'đơn nhập hàng chưa nhận xong' => DB::table('purchase_orders')->where('tenant_id', $tenant)->where('warehouse_id', $warehouse->id)->whereIn('status', ['draft', 'ordered', 'partial'])->count(),
            'phiếu chuyển kho chưa hoàn tất' => DB::table('stock_transfers')->where('tenant_id', $tenant)->whereIn('status', [StockTransfer::DRAFT, StockTransfer::IN_TRANSIT])
                ->where(fn ($q) => $q->where('from_warehouse_id', $warehouse->id)->orWhere('to_warehouse_id', $warehouse->id))->count(),
            'phiếu kiểm/điều chỉnh chưa duyệt' => DB::table('inventory_adjustments')->where('tenant_id', $tenant)->where('warehouse_id', $warehouse->id)->whereIn('status', [InventoryAdjustment::DRAFT, InventoryAdjustment::PENDING])->count(),
            'gian hàng đang lấy tồn từ kho này' => DB::table('marketplace_accounts')->where('tenant_id', $tenant)->where('warehouse_id', $warehouse->id)->where('status', 'connected')->count(),
        ]);
        if ($open !== []) {
            $list = collect($open)->map(fn ($n, $what) => "{$n} {$what}")->implode(', ');
            throw new InvalidStockOperation("Kho còn {$list}. Hãy xử lý xong hoặc chuyển sang kho khác trước khi ngừng.");
        }
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
