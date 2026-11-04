<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerExporter;
use App\Modules\Sales\Services\CustomerImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer import / export (CSV, XLSX). Both carry personal data (phones, addresses): each has its own permission (customers.import, customers.export).
 */
class CustomerTransferController extends Controller
{
    public function import(Request $request, CustomerImporter $importer, AuditLogger $audit): JsonResponse
    {
        $this->authorize('import', Customer::class);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $parsed = $importer->read($file->getRealPath(), $extension);

        if ($parsed['errors']) {
            return response()->json(['message' => 'Tệp không hợp lệ.', 'created' => 0, 'updated' => 0, 'errors' => $parsed['errors']], 422);
        }
        if ($parsed['rows'] === []) {
            return response()->json(['message' => 'Tệp không có dòng dữ liệu.', 'created' => 0, 'updated' => 0, 'errors' => []], 422);
        }

        $result = $importer->import($parsed['rows'], (bool) ($data['dry_run'] ?? false));
        if (! $result['dry_run'] && ! $result['errors']) {
            $audit->record('customer.imported', null, ['file' => $file->getClientOriginalName(), 'created' => $result['created'], 'updated' => $result['updated']]);
        }

        return response()->json(
            ['message' => $result['errors'] ? 'Có lỗi, chưa nhập dòng nào.' : 'OK', 'total' => count($parsed['rows'])] + $result,
            $result['errors'] ? 422 : 200,
        );
    }

    public function export(Request $request, CustomerExporter $exporter, AuditLogger $audit): StreamedResponse
    {
        $this->authorize('export', Customer::class);
        $data = $request->validate(['format' => ['nullable', 'in:csv,xlsx'], 'ids' => ['nullable', 'string', 'max:20000']]);
        $ids = filled($data['ids'] ?? null) ? array_values(array_filter(explode(',', $data['ids']))) : null;
        // The file carries phones and addresses in clear: who took it, and which customers, is kept.
        $audit->record('customer.exported', null, ['format' => $data['format'] ?? 'xlsx', 'scope' => $ids === null ? 'all' : count($ids).' selected']);

        return $exporter->stream($data['format'] ?? 'xlsx', $ids);
    }

    public function template(): StreamedResponse
    {
        $this->authorize('import', Customer::class);

        return response()->streamDownload(function () {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(array_values(CustomerImporter::COLUMNS)));
            $writer->addRow(Row::fromValues(['', 'Nguyễn Văn An', '0912345678', 'an@example.com', '12 Lê Lợi, Phường Bến Thành, TP. Hồ Chí Minh', '', '15/08/1990', 'Nam', '', 'Khách quen, Zalo', 'Đang giao dịch', 'Giao giờ hành chính']));
            $writer->addRow(Row::fromValues(['', 'Công ty TNHH Minh Phát', '0283456789', '', '45 Nguyễn Trãi, Hà Nội', '', '', '', '0101234567', 'Sỉ', '', '']));
            $writer->close();
        }, 'mau-nhap-khach-hang.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
