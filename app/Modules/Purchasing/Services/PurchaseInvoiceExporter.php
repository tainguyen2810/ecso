<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\SafeRow;
use Illuminate\Support\Carbon;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Xuất bảng kê" on Hoá đơn mua vào: one row per invoice, for the accountant or the VAT return. */
class PurchaseInvoiceExporter
{
    public const COLUMNS = ['STT', 'Ký hiệu', 'Số hoá đơn', 'Ngày', 'Tên người bán', 'MST người bán', 'Tiền chưa thuế', 'Thuế suất', 'Tiền thuế GTGT', 'Tổng thanh toán', 'Trạng thái', 'Phiếu nhập', 'Cần xem lại'];

    public const ISSUES = [
        'buyer_mismatch' => 'MST người mua không phải của cửa hàng',
        'no_buyer_tax_code' => 'Thiếu MST người mua',
        'no_authority_code' => 'Thiếu mã của cơ quan thuế',
        'total_mismatch' => 'Cộng tiền không khớp',
    ];

    public function stream(?string $month): StreamedResponse
    {
        $context = app(TenantContext::class);
        $tenant = $context->tenant();
        $membership = $context->membership();

        return response()->streamDownload(
            fn () => TenantContext::run($tenant, fn () => $this->write($month), $membership),
            'hoa-don-mua-vao-'.($month ?? now()->format('Ymd')).'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function write(?string $month): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile('php://output');
        $writer->addRow(SafeRow::fromValues(self::COLUMNS));
        $query = PurchaseInvoice::query()->with('purchaseOrder:id,number')->orderBy('issued_on')->orderBy('id');
        if ($month) {
            $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
            $query->whereBetween('issued_on', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]);
        }
        $n = 0;
        $query->chunk(500, function ($invoices) use ($writer, &$n) {
            foreach ($invoices as $i) {
                $writer->addRow(SafeRow::fromValues([
                    ++$n, $i->series, $i->number, $i->issued_on?->format('d/m/Y') ?? '', $i->seller_name, $i->seller_tax_code,
                    (string) $i->subtotal, $i->vat_rate !== null ? ((float) $i->vat_rate).'%' : '', (string) $i->vat_amount, (string) $i->total,
                    ['open' => 'Chưa vào phiếu nhập', 'linked' => 'Đã vào phiếu nhập', 'ignored' => 'Không đưa vào'][$i->status] ?? $i->status,
                    (string) $i->purchaseOrder?->number,
                    implode('; ', array_map(fn ($k) => self::ISSUES[$k] ?? $k, $i->issues ?? [])),
                ]));
            }
        });
        $writer->close();
    }
}
