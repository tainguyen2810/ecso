<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\SapoPurchaseImporter;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cấu hình › Chuyển dữ liệu › Nhà cung cấp và nhập hàng: one "sapo-nhap-hang-….json" file, checked (dry_run) then imported.
 */
class SapoPurchaseImportController extends Controller
{
    public function import(Request $request, SapoPurchaseImporter $importer, TenantContext $context): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        // It also writes what was paid to suppliers.
        abort_unless($context->can('purchasing.payment'), 403, 'Bạn chưa có quyền chuyển dữ liệu nhập hàng.');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:json,txt'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $file = $importer->read($data['file']->getRealPath());
        if ($file === null) {
            return response()->json(['message' => 'File không phải dữ liệu nhập hàng lấy từ Sapo bằng nút "Lấy nhập hàng Sapo".', 'errors' => ['file' => ['File không đúng định dạng.']]], 422);
        }
        if ($file['purchases'] === [] && $file['suppliers'] === []) {
            return response()->json(['message' => 'File không có nhà cung cấp hay đơn nhập nào.', 'errors' => ['file' => ['File trống.']]], 422);
        }

        @set_time_limit(300);
        $result = $importer->import($file, (bool) ($data['dry_run'] ?? false));

        return response()->json(['message' => 'OK', 'store' => $file['store']] + $result);
    }
}
