<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductExporter;
use App\Modules\Catalog\Services\ProductImporter;
use App\Modules\Catalog\Services\SapoGoReader;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\SafeRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product import / export (CSV, XLSX).
 */
class ProductTransferController extends Controller
{
    public function import(Request $request, ProductImporter $importer, SapoGoReader $sapoGo, TenantContext $context): JsonResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,json'],
            'dry_run' => ['sometimes', 'boolean'],
            'warehouse_id' => ['nullable', 'string'],
            'skip_invalid' => ['sometimes', 'boolean'],
            // Sapo branch name => warehouse ulid ('' leaves that branch's stock out).
            'branch_warehouses' => ['sometimes', 'array', 'max:50'],
            'branch_warehouses.*' => ['nullable', 'string', 'max:64'],
            'with_images' => ['sometimes', 'boolean'],
        ]);

        $warehouse = null;
        if (! empty($data['warehouse_id'])) {
            $warehouse = Warehouse::query()->where('ulid', $data['warehouse_id'])->firstOrFail();
        }

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension());
        $parsed = $extension === 'json'
            ? $sapoGo->read($file->getRealPath())
            : $importer->read($file->getRealPath(), $extension === 'xlsx' ? 'xlsx' : 'csv');

        // Which app the file came from and how its columns were read, so the user can check before importing.
        $fileInfo = ['source' => $parsed['source'], 'columns' => $parsed['columns']];
        if ($parsed['errors']) {
            return response()->json(['message' => 'Tệp không hợp lệ.', 'created' => 0, 'updated' => 0, 'errors' => $parsed['errors']] + $fileInfo, 422);
        }
        if ($parsed['rows'] === []) {
            return response()->json(['message' => 'Tệp không có dòng dữ liệu.', 'created' => 0, 'updated' => 0, 'errors' => []] + $fileInfo, 422);
        }

        try {
            $branchWarehouses = $importer->branchWarehouses(array_map('strval', array_keys($parsed['branches'])), $data['branch_warehouses'] ?? [], $warehouse);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['branch_warehouses' => [$e->getMessage()]]], 422);
        }
        $fileInfo['branches'] = array_map(fn (string $name) => [
            'name' => $name, 'quantity' => $parsed['branches'][$name], 'warehouse_id' => $branchWarehouses[$name]?->ulid,
        ], array_map('strval', array_keys($parsed['branches'])));

        $bringsStock = $warehouse !== null || array_filter($branchWarehouses) !== [];
        abort_if($bringsStock && ! $context->can('inventory.receive'), 403, 'Bạn không có quyền nhập tồn đầu kỳ.');

        $result = $importer->import($parsed['rows'], (bool) ($data['dry_run'] ?? false), $warehouse, (bool) ($data['skip_invalid'] ?? false), $branchWarehouses, (bool) ($data['with_images'] ?? true));

        return response()->json(
            ['message' => $result['errors'] ? 'Có lỗi, chưa nhập dòng nào.' : 'OK', 'total' => count($parsed['rows'])] + $result + $fileInfo,
            $result['errors'] ? 422 : 200,
        );
    }

    public function export(Request $request, ProductExporter $exporter): StreamedResponse
    {
        $this->authorize('viewAny', Product::class);

        return $exporter->stream((string) $request->query('format', 'csv'));
    }

    public function template(): StreamedResponse
    {
        $this->authorize('viewAny', Product::class);

        return response()->streamDownload(function () {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $writer->addRow(SafeRow::fromValues(ProductImporter::COLUMNS));
            $writer->addRow(SafeRow::fromValues(['AO-THUN', 'Áo thun cotton', 'AT-TRANG-M', 'Trắng / M', '8930000000011', 'Áo', 'ECSO', '159000', '200', 'active', '10', '85000']));
            $writer->addRow(SafeRow::fromValues(['AO-THUN', 'Áo thun cotton', 'AT-TRANG-L', 'Trắng / L', '', 'Áo', 'ECSO', '159000', '220', 'active', '5', '85000']));
            $writer->close();
        }, 'mau-import-san-pham.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
