<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {
            $supplier = new Supplier($data);
            $supplier->forceFill(['code' => DocumentNumber::next('NCC')])->save();
            $this->audit->record('supplier.created', $supplier, ['code' => $supplier->code]);

            return $supplier;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->fill($data)->save();
        $this->audit->record('supplier.updated', $supplier, ['code' => $supplier->code]);

        return $supplier;
    }

    public function delete(Supplier $supplier): void
    {
        if ($supplier->orders()->exists() || $supplier->payments()->exists() || $supplier->returns()->exists()) {
            throw ValidationException::withMessages(['supplier' => 'Nhà cung cấp đã có giao dịch. Hãy chuyển sang ngừng giao dịch thay vì xóa.']);
        }
        $supplier->delete();
        $this->audit->record('supplier.deleted', $supplier, ['code' => $supplier->code]);
    }
}
