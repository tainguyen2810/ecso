<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Customer;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports customers with the import headers first (so the file can be edited and imported back), then the purchase
 * figures. The ids given limit the export to those customers (the rows ticked in the list).
 */
class CustomerExporter
{
    public const EXTRA = ['Nhân viên phụ trách', 'Số đơn', 'Tổng chi tiêu', 'Công nợ', 'Mua gần nhất', 'Ngày tạo'];

    /**
     * @param  list<string>|null  $ids
     */
    public function stream(string $format, ?array $ids = null): StreamedResponse
    {
        $format = $format === 'csv' ? 'csv' : 'xlsx';
        $filename = 'khach-hang-'.now()->format('Ymd-His').'.'.$format;

        // The body is streamed after the middleware stack (and its tenant context) has finished, so the
        // tenant is captured now and re-entered inside the callback.
        $context = app(TenantContext::class);
        $tenant = $context->tenant();
        $membership = $context->membership();

        return response()->streamDownload(fn () => TenantContext::run($tenant, fn () => $this->write($format, $ids), $membership), $filename, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<string>|null  $ids
     */
    private function write(string $format, ?array $ids): void
    {
        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile('php://output');
        $writer->addRow(Row::fromValues([...array_values(CustomerImporter::COLUMNS), ...self::EXTRA]));
        $genders = ['male' => 'Nam', 'female' => 'Nữ', 'other' => 'Khác'];
        $statuses = ['active' => 'Đang giao dịch', 'inactive' => 'Ngừng giao dịch'];

        Customer::query()->withStats()->with(['customerGroup:id,code,name', 'assignee:id,name'])
            ->when($ids !== null, fn ($q) => $q->whereIn('customers.ulid', $ids))
            ->orderBy('customers.id')
            ->chunk(500, function ($customers) use ($writer, $genders, $statuses) {
                foreach ($customers as $c) {
                    $writer->addRow(Row::fromValues([
                        $c->code, $c->name, (string) $c->phone, (string) $c->email, (string) $c->address, (string) $c->customerGroup?->code,
                        (string) $c->birthday?->format('d/m/Y'), $genders[$c->gender] ?? '', (string) $c->tax_code,
                        implode(', ', $c->tags ?? []), $statuses[$c->status] ?? '', (string) $c->note,
                        (string) $c->assignee?->name, (int) $c->orders_count, (string) $c->total_spent, (string) $c->debt,
                        $c->last_order_at ? Carbon::parse($c->last_order_at)->format('d/m/Y') : '', (string) $c->created_at?->format('d/m/Y'),
                    ]));
                }
            });

        $writer->close();
    }
}
