<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use App\Shared\Support\SafeRow;
use Carbon\CarbonInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Xuất file" on the shipment list (XLSX), same filters as on screen. Marketplace orders keep the masked phone. */
class ShipmentExporter
{
    public const MAX_ROWS = 20000;

    public const HEADINGS = [
        'Mã vận đơn', 'Đơn vị vận chuyển', 'Mã đơn hàng', 'Kênh', 'Người nhận', 'Điện thoại', 'Địa chỉ', 'Trạng thái',
        'Tiền thu hộ (COD)', 'Trạng thái COD', 'Phí ước tính', 'Phí thực tế', 'Khối lượng (g)', 'Số kiện', 'Kho',
        'Ngày lấy hàng', 'Ngày giao', 'Số lần giao', 'Lý do thất bại',
    ];

    private const COD = [
        Shipment::COD_NONE => 'Không thu hộ', Shipment::COD_PENDING => 'Chưa thu', Shipment::COD_COLLECTED => 'Hãng đang giữ',
        Shipment::COD_REMITTED => 'Đã đối soát', Shipment::COD_RETURNED => 'Hoàn, không thu',
    ];

    /** @param array<string, mixed> $filters */
    public function stream(array $filters): StreamedResponse
    {
        // The body is streamed after the tenant middleware has finished: capture the tenant and re-enter it.
        $context = app(TenantContext::class);
        $tenant = $context->tenant();
        $membership = $context->membership();

        return response()->streamDownload(
            fn () => TenantContext::run($tenant, fn () => $this->write($filters), $membership),
            'van-don-'.now()->format('Ymd-His').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** @param array<string, mixed> $filters */
    private function write(array $filters): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile('php://output');
        $writer->addRow(SafeRow::fromValues(self::HEADINGS));
        $written = 0;
        $tz = ReportRange::timezone();
        $at = fn (?CarbonInterface $d) => $d ? $d->copy()->setTimezone($tz)->format('d/m/Y H:i') : '';

        ShipmentQuery::build($filters)->with(['order.channel', 'warehouse'])->orderByDesc('last_event_at')->orderByDesc('id')
            ->chunk(500, function ($shipments) use ($writer, &$written, $at) {
                foreach ($shipments as $s) {
                    if ($written++ >= self::MAX_ROWS) {
                        return false;
                    }
                    /** @var Shipment $s */
                    $o = $s->order;
                    $writer->addRow(SafeRow::fromValues([
                        (string) $s->tracking_number,
                        (string) ($s->carrier_name ?? $s->carrier),
                        (string) $o?->external_order_id,
                        (string) ($o?->channel->name ?? ($o?->isManual() ? 'Kênh riêng' : ucfirst((string) $o?->marketplace))),
                        (string) $o?->buyer_name,
                        (string) ($o === null ? '' : ($o->ownsCustomerData() ? ($o->buyer_phone ?? $o->buyer_phone_masked) : $o->buyer_phone_masked)),
                        (string) $o?->shipping_address,
                        ShipmentStatus::label($s->status),
                        (string) $s->cod_amount,
                        self::COD[$s->cod_status] ?? $s->cod_status,
                        (string) $s->fee_estimated,
                        (string) $s->fee_actual,
                        (string) $s->weight_g,
                        (string) $s->packages,
                        (string) $s->warehouse?->name,
                        $at($s->picked_up_at),
                        $at($s->delivered_at),
                        (string) $s->attempts,
                        (string) $s->failure_reason,
                    ]));
                }
            });
        $writer->close();
    }
}
