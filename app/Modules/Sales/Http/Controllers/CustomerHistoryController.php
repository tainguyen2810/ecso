<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Http\Resources\ReturnResource;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Http\Resources\PaymentResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerLedger;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The tabs of the customer page, each paged on the server: orders, payments, returns, activity, and the debt book
 * as a spreadsheet.
 */
class CustomerHistoryController extends Controller
{
    public function orders(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $this->authorize('view', $customer);

        return OrderResource::collection($customer->orders()->with('channel')->orderByDesc('placed_at')->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function payments(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $this->authorize('view', $customer);

        return PaymentResource::collection($customer->payments()->with(['order', 'creator'])->orderByDesc('paid_at')->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function returns(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $this->authorize('view', $customer);
        $returns = OrderReturn::query()->whereIn('order_id', $customer->orders()->select('id'))
            ->with(['order', 'lines.item', 'lines.variant'])->latest('id')->paginate($this->perPage($request));

        return ReturnResource::collection($returns);
    }

    /**
     * What happened to the customer: profile changes, debt documents, receipts, merges (audit log of this customer).
     */
    public function activity(Request $request, Customer $customer, TenantContext $context): JsonResponse
    {
        $this->authorize('view', $customer);
        $logs = AuditLog::query()->with('actor:id,name')
            ->where('tenant_id', $context->id())->where('subject_type', class_basename(Customer::class))->where('subject_id', $customer->id)
            ->latest('id')->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'actor' => $l->actor?->name,
                'changes' => $l->changes,
                'at' => $l->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }

    public function ledgerExport(Customer $customer, CustomerLedger $ledger): StreamedResponse
    {
        $this->authorize('view', $customer);
        $lines = array_reverse($ledger->entries($customer));
        $labels = ['order' => 'Đơn hàng', 'return' => 'Trả hàng', 'payment' => 'Thu tiền', 'refund' => 'Hoàn tiền', 'opening' => 'Nợ đầu kỳ', 'adjustment' => 'Điều chỉnh', 'receipt' => 'Thu nợ'];

        return response()->streamDownload(function () use ($customer, $lines, $labels) {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(["Sổ công nợ {$customer->code} · {$customer->name}", 'Xuất lúc '.now()->format('d/m/Y H:i')]));
            $writer->addRow(Row::fromValues(['Ngày', 'Loại', 'Chứng từ', 'Diễn giải', 'Phát sinh tăng', 'Phát sinh giảm', 'Còn nợ']));
            foreach ($lines as $l) {
                $change = (float) $l['change'];
                $writer->addRow(Row::fromValues([
                    $l['at'] ? date('d/m/Y H:i', (int) strtotime($l['at'])) : '', $labels[$l['type']] ?? $l['type'], (string) $l['ref'], (string) $l['note'],
                    $change > 0 ? $change : '', $change < 0 ? -$change : '', (float) $l['balance'],
                ]));
            }
            $writer->close();
        }, "cong-no-{$customer->code}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function perPage(Request $request): int
    {
        return (int) ($request->validate(['per_page' => ['nullable', 'integer', 'min:5', 'max:100']])['per_page'] ?? 20);
    }
}
