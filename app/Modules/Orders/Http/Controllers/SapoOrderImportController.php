<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\SapoOrderImporter;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cấu hình › Chuyển dữ liệu › Đơn hàng cũ: one "sapo-don-hang-….json" file at a time, checked (dry_run) then imported.
 */
class SapoOrderImportController extends Controller
{
    public function import(Request $request, SapoOrderImporter $importer, TenantContext $context): JsonResponse
    {
        $this->authorize('create', Order::class);
        // It also makes customer cards for buyers not brought over yet.
        abort_unless($context->can('customers.import'), 403, 'Bạn chưa có quyền chuyển dữ liệu.');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:json,txt'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $file = $importer->read($data['file']->getRealPath());
        if ($file === null) {
            return response()->json(['message' => 'File không phải đơn hàng lấy từ Sapo bằng nút "Lấy đơn hàng Sapo".', 'errors' => ['file' => ['File không đúng định dạng.']]], 422);
        }
        if ($file['orders'] === []) {
            return response()->json(['message' => 'File không có đơn hàng nào.', 'errors' => ['file' => ['File không có đơn hàng nào.']]], 422);
        }

        @set_time_limit(300);
        $result = $importer->import($file, (bool) ($data['dry_run'] ?? false));

        return response()->json(['message' => 'OK', 'store' => $file['store'], 'part' => $file['part'], 'parts' => $file['parts']] + $result);
    }
}
