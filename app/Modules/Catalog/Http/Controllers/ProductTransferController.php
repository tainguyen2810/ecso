<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductExporter;
use App\Modules\Catalog\Services\ProductImporter;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product import / export (CSV, XLSX).
 */
class ProductTransferController extends Controller
{
    public function import(Request $request, ProductImporter $importer, TenantContext $context): JsonResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx'],
            'dry_run' => ['sometimes', 'boolean'],
            'warehouse_id' => ['nullable', 'string'],
        ]);

        $warehouse = null;
        if (! empty($data['warehouse_id'])) {
            $warehouse = Warehouse::query()->where('ulid', $data['warehouse_id'])->firstOrFail();
            abort_unless($context->can('inventory.receive'), 403, 'Bạn không có quyền nhập tồn đầu kỳ.');
        }

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $parsed = $importer->read($file->getRealPath(), $extension);

        if ($parsed['errors']) {
            return response()->json(['message' => 'Tệp không hợp lệ.', 'created' => 0, 'updated' => 0, 'errors' => $parsed['errors']], 422);
        }
        if ($parsed['rows'] === []) {
            return response()->json(['message' => 'Tệp không có dòng dữ liệu.', 'created' => 0, 'updated' => 0, 'errors' => []], 422);
        }

        $result = $importer->import($parsed['rows'], (bool) ($data['dry_run'] ?? false), $warehouse);

        return response()->json(
            ['message' => $result['errors'] ? 'Có lỗi, chưa nhập dòng nào.' : 'OK', 'total' => count($parsed['rows'])] + $result,
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
            $writer->addRow(Row::fromValues(ProductImporter::COLUMNS));
            $writer->addRow(Row::fromValues(['AO-THUN', 'Áo thun cotton', 'AT-TRANG-M', 'Trắng / M', '8930000000011', 'Áo', 'ECSO', '159000', '200', 'active', '10', '85000']));
            $writer->addRow(Row::fromValues(['AO-THUN', 'Áo thun cotton', 'AT-TRANG-L', 'Trắng / L', '', 'Áo', 'ECSO', '159000', '220', 'active', '5', '85000']));
            $writer->close();
        }, 'mau-import-san-pham.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
