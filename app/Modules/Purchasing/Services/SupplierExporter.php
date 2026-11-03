<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Tenancy\Support\TenantContext;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Xuất file" on the supplier list: contact details with what was bought, paid and is still owed. */
class SupplierExporter
{
    public const COLUMNS = ['Mã NCC', 'Tên nhà cung cấp', 'Người liên hệ', 'Số điện thoại', 'Email', 'Địa chỉ', 'Mã số thuế', 'Trạng thái', 'Số đơn nhập', 'Tổng nhập', 'Đã trả', 'Công nợ', 'Ghi chú'];

    public function stream(): StreamedResponse
    {
        // The body is streamed after the tenant middleware has finished: capture the tenant and re-enter it.
        $context = app(TenantContext::class);
        $tenant = $context->tenant();
        $membership = $context->membership();

        return response()->streamDownload(
            fn () => TenantContext::run($tenant, fn () => $this->write(), $membership),
            'nha-cung-cap-'.now()->format('Ymd-His').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function write(): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile('php://output');
        $writer->addRow(Row::fromValues(self::COLUMNS));
        Supplier::query()->withBalance()->orderBy('suppliers.name')->chunk(500, function ($suppliers) use ($writer) {
            foreach ($suppliers as $s) {
                $writer->addRow(Row::fromValues([
                    $s->code, $s->name, (string) $s->contact_name, (string) $s->phone, (string) $s->email, (string) $s->address, (string) $s->tax_code,
                    $s->is_active ? 'Đang giao dịch' : 'Ngừng giao dịch', (int) $s->orders_count, (string) $s->purchased, (string) $s->paid, (string) $s->debt, (string) $s->note,
                ]));
            }
        });
        $writer->close();
    }
}
