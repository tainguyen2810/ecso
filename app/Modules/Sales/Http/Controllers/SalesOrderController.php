<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderTags;
use App\Modules\Sales\Http\Resources\PaymentResource;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Sales\Services\ManualOrderService;
use App\Modules\Sales\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Orders of the seller's own channels: create, edit, process, collect payments. */
class SalesOrderController extends Controller
{
    public function store(Request $request, ManualOrderService $orders): JsonResponse
    {
        $this->authorize('create', Order::class);
        $data = $request->validate([
            'channel_id' => ['required', 'string'],
            'stage' => ['nullable', Rule::in(['pending', 'confirmed', 'completed'])],
            ...$this->rules(),
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            ...$this->lineRules(),
            'payments' => ['array', 'max:5'],
            'payments.*.method' => ['required', Rule::in(OrderPayment::METHODS)],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'exchange_return_id' => ['nullable', 'string'],
        ]);

        $order = $orders->create($data);

        return (new OrderResource($order->load(['channel', 'customer', 'warehouse'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Order $order, ManualOrderService $orders): OrderResource
    {
        $this->authorize('create', Order::class);
        $data = $request->validate([
            ...$this->rules(),
            'lines' => ['sometimes', 'array', 'min:1', 'max:200'],
            ...$this->lineRules(),
        ]);

        return new OrderResource($orders->update($order, $data)->load(['channel', 'customer', 'warehouse']));
    }

    public function action(Request $request, Order $order, string $action, ManualOrderService $orders): OrderResource
    {
        $this->authorize('update', $order);
        $params = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
        ]);

        return new OrderResource($orders->transition($order, $action, $params)->load(['channel', 'customer']));
    }

    public function pay(Request $request, Order $order, PaymentService $payments): JsonResponse
    {
        $this->authorize('pay', $order);
        $data = $request->validate([
            'kind' => ['nullable', Rule::in([OrderPayment::PAYMENT, OrderPayment::REFUND])],
            'method' => ['required', Rule::in(OrderPayment::METHODS)],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        return (new PaymentResource($payments->record($order, $data)->load('creator')))->response()->setStatusCode(201);
    }

    public function void(Request $request, OrderPayment $payment, PaymentService $payments): PaymentResource
    {
        $this->authorize('pay', Order::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new PaymentResource($payments->void($payment, $data['reason'])->load('creator'));
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['required_with:customer', 'string', 'max:150'],
            'customer.phone' => ['nullable', 'string', 'max:20'],
            'customer.address' => ['nullable', 'string', 'max:500'],
            'buyer_name' => ['nullable', 'string', 'max:150'],
            'buyer_phone' => ['nullable', 'string', 'max:20'],
            'shipping_address' => ['nullable', 'string', 'max:500'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'is_cod' => ['boolean'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'ship_by_at' => ['nullable', 'date'],
            'deliver_on' => ['nullable', 'date'],
            'tags' => ['nullable', 'array', 'max:'.OrderTags::MAX],
            'tags.*' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function lineRules(): array
    {
        return [
            'lines.*.variant_id' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }
}
