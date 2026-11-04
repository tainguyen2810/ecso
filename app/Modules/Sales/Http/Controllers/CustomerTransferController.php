<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sales\Http\Resources\CustomerImportResource;
use App\Modules\Sales\Jobs\ImportCustomersJob;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerImport;
use App\Modules\Sales\Services\CustomerExporter;
use App\Modules\Sales\Services\CustomerImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $parsed = $importer->read($file->getRealPath(), $extension, CustomerImporter::syncRows());

        if ($parsed['more'] && ! $parsed['errors']) {
            // Too many rows to import while the user waits: checked, then imported, in the background.
            $import = new CustomerImport;
            $import->forceFill(['user_id' => $request->user()->id, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'status' => CustomerImport::PENDING_CHECK])->save();
            $import->forceFill(['path' => $file->storeAs('customer-imports', $import->ulid.'.'.$extension, ImportCustomersJob::DISK)])->save();
            ImportCustomersJob::dispatch($import->id, ImportCustomersJob::CHECK);

            return response()->json(['queued' => true, 'import' => new CustomerImportResource($import->refresh())], 202);
        }
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

    /** The latest background import of the current user in the last day (to show its progress again). */
    public function latestImport(Request $request): JsonResponse
    {
        $this->authorize('import', Customer::class);
        $import = CustomerImport::query()->where('user_id', $request->user()->id)->where('created_at', '>=', now()->subDay())->latest('id')->first();

        return response()->json(['data' => $import ? new CustomerImportResource($import) : null]);
    }

    public function showImport(CustomerImport $import): CustomerImportResource
    {
        $this->authorize('import', Customer::class);

        return new CustomerImportResource($import);
    }

    /** Imports a checked file. One background import runs at a time per business. */
    public function runImport(CustomerImport $import): CustomerImportResource
    {
        $this->authorize('import', Customer::class);
        if ($import->status !== CustomerImport::CHECKED) {
            throw ValidationException::withMessages(['import' => 'Tệp này chưa kiểm tra xong hoặc đã nhập rồi.']);
        }
        if (CustomerImport::query()->whereIn('status', [CustomerImport::QUEUED, CustomerImport::IMPORTING])->exists()) {
            throw ValidationException::withMessages(['import' => 'Đang có một tệp khác được nhập, vui lòng chờ xong.']);
        }
        if (! $import->claim(CustomerImport::CHECKED, CustomerImport::QUEUED)) {
            throw ValidationException::withMessages(['import' => 'Tệp này đã được nhập.']);
        }
        ImportCustomersJob::dispatch($import->id, ImportCustomersJob::IMPORT);

        return new CustomerImportResource($import->refresh());
    }

    public function export(Request $request, CustomerExporter $exporter, AuditLogger $audit): StreamedResponse
    {
        $this->authorize('export', Customer::class);
        $data = $request->validate(['format' => ['nullable', 'in:csv,xlsx'], 'ids' => ['nullable', 'string', 'max:20000'], ...CustomerController::filterRules()]);
        $ids = filled($data['ids'] ?? null) ? array_values(array_filter(explode(',', $data['ids']))) : null;
        // Without ticked rows, the file holds what the list shows: the same filters and order.
        $filters = array_filter(array_diff_key($data, array_flip(['format', 'ids', 'per_page', 'sort', 'dir'])), fn ($v) => $v !== null && $v !== '');
        // The file carries phones and addresses in clear: who took it, and which customers, is kept.
        $audit->record('customer.exported', null, [
            'format' => $data['format'] ?? 'xlsx',
            'scope' => $ids !== null ? count($ids).' selected' : ($filters ? 'filtered' : 'all'),
            'filters' => $filters ?: null,
        ]);

        return $exporter->stream($data['format'] ?? 'xlsx', $ids, $ids === null ? $data : []);
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
