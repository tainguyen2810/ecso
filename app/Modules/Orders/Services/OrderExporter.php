<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\SafeRow;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Order list export (XLSX) with the same filters as the list screen. Marketplace orders only carry the masked phone,
 * like on screen.
 */
class OrderExporter
{
    public const MAX_ROWS = 20000;

    public const HEADINGS = [
        'Mã đơn', 'Ngày đặt', 'Nguồn', 'Kênh / gian hàng', 'Khách hàng', 'Người nhận', 'Điện thoại', 'Địa chỉ giao',
        'Sản phẩm', 'Số lượng', 'Tiền hàng', 'Phí giao', 'Giảm giá', 'Tổng tiền', 'Đã thu', 'Thanh toán',
        'Trạng thái', 'Đơn vị vận chuyển', 'Mã vận đơn', 'Người xử lý', 'Lý do hủy', 'Tags', 'Ngày hẹn giao',
    ];

    private const STATUS = [
        'UNPAID' => 'Chờ thanh toán', 'PENDING_CONFIRMATION' => 'Chờ xác nhận', 'READY_TO_PACK' => 'Chờ đóng gói',
        'PACKED' => 'Chờ bàn giao', 'HANDED_OVER' => 'Đã bàn giao', 'IN_TRANSIT' => 'Đang giao', 'DELIVERED' => 'Đã giao',
        'COMPLETED' => 'Hoàn tất', 'CANCELLED' => 'Đã hủy', 'FAILED_DELIVERY' => 'Giao thất bại',
        'RETURN_REQUESTED' => 'Yêu cầu trả', 'RETURNED' => 'Đã hoàn', 'UNKNOWN_STATUS' => 'Chưa rõ trạng thái',
    ];

    private const PAYMENT = ['unpaid' => 'Chưa thanh toán', 'partial' => 'Thanh toán một phần', 'paid' => 'Đã thanh toán'];

    public function __construct(private readonly OrderQuery $orders) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function stream(array $filters, ?int $userId): StreamedResponse
    {
        $context = app(TenantContext::class);
        $tenant = $context->tenant();
        $membership = $context->membership();

        return response()->streamDownload(
            fn () => TenantContext::run($tenant, fn () => $this->write($filters, $userId), $membership),
            'don-hang-'.now()->format('Ymd-His').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function write(array $filters, ?int $userId): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile('php://output');
        $writer->addRow(SafeRow::fromValues(self::HEADINGS));
        $written = 0;

        $this->orders->build($filters, $userId)
            ->with(['account', 'channel', 'customer', 'assignee', 'items'])
            ->orderByDesc('placed_at')->orderByDesc('id')
            ->chunk(500, function ($orders) use ($writer, &$written) {
                foreach ($orders as $o) {
                    if ($written++ >= self::MAX_ROWS) {
                        return false;
                    }
                    /** @var Order $o */
                    $writer->addRow(SafeRow::fromValues([
                        $o->external_order_id,
                        $o->placed_at?->format('d/m/Y H:i') ?? '',
                        $o->isManual() ? 'Kênh riêng' : ucfirst($o->marketplace),
                        (string) ($o->account->shop_name ?? $o->channel?->name),
                        (string) $o->customer?->name,
                        (string) $o->buyer_name,
                        (string) ($o->isManual() ? $o->buyer_phone : $o->buyer_phone_masked),
                        (string) $o->shipping_address,
                        $o->items->map(fn (OrderItem $i) => $i->quantity.' x '.$i->name.($i->variant_name ? ' ('.$i->variant_name.')' : ''))->implode('; '),
                        (int) $o->items->sum('quantity'),
                        (float) $o->subtotal,
                        (float) $o->shipping_fee,
                        (float) $o->discount,
                        (float) $o->total,
                        (float) $o->paid_amount,
                        self::PAYMENT[$o->payment_status] ?? '',
                        self::STATUS[$o->status] ?? $o->status,
                        (string) $o->shipping_carrier,
                        (string) $o->tracking_number,
                        (string) $o->assignee?->name,
                        (string) $o->cancel_reason,
                        implode(', ', $o->tags ?? []),
                        $o->deliver_on?->format('d/m/Y') ?? '',
                    ]));
                }
            });

        $writer->close();
    }
}
