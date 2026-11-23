<?php

namespace App\Modules\Cashbook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Services\SapoVoucherImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cấu hình › Chuyển dữ liệu › Sổ quỹ: one "sapo-so-quy-….json" file, checked (dry_run) then imported.
 */
class SapoVoucherImportController extends Controller
{
    public function import(Request $request, SapoVoucherImporter $importer): JsonResponse
    {
        $this->authorize('create', CashVoucher::class);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:json,txt'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $file = $importer->read($data['file']->getRealPath());
        if ($file === null) {
            return response()->json(['message' => 'File không phải sổ quỹ lấy từ Sapo bằng nút "Lấy sổ quỹ Sapo".', 'errors' => ['file' => ['File không đúng định dạng.']]], 422);
        }

        @set_time_limit(300);
        $result = $importer->import($file, (bool) ($data['dry_run'] ?? false));

        return response()->json(['message' => 'OK', 'store' => $file['store']] + $result);
    }
}
