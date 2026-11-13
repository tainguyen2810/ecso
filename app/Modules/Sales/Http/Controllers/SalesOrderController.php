<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Geo\Services\VnAddress;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Http\Resources\ReturnResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\ReturnService;
use App\Modules\Orders\Support\OrderTags;
use App\Modules\Sales\Http\Resources\PaymentResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Sales\Services\ManualOrderService;
use App\Modules\Sales\Services\PaymentService;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'placed_at' => ['nullable', 'date', 'before_or_equal:now'],
            'counter' => ['sometimes', 'boolean'], // sold at the till (POS screen): needs an open shift
        ]);
        $this->guardSettings($request, $data);

        $order = $orders->create($data);

        return (new OrderResource($order->load(['channel', 'customer', 'warehouse'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Order $order, ManualOrderService $orders): OrderResource
    {
        // Editing lines, prices and the buyer of an existing order is order handling, not only selling.
        $this->authorize('create', Order::class);
        $this->authorize('update', $order);
        $data = $request->validate([
            ...$this->rules(),
            'version' => ['sometimes', 'integer'], // the version the editor loaded: refused if someone saved since
            'lines' => ['sometimes', 'array', 'min:1', 'max:200'],
            ...$this->lineRules(),
        ]);
        $this->guardSettings($request, $data);

        return new OrderResource($orders->update($order, $data)->load(['channel', 'customer', 'warehouse']));
    }

    /** "Hủy hóa đơn" of a completed sale: everything back to stock and the money back to the buyer, in one step. */
    public function voidSale(Request $request, Order $order, ReturnService $returns): ReturnResource
    {
        $this->authorize('cancel', $order);
        $this->authorize('pay', $order);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new ReturnResource($returns->voidSale($order, $data['reason'])->load(['order', 'warehouse', 'lines.item', 'lines.variant']));
    }

    public function action(Request $request, Order $order, string $action, ManualOrderService $orders): OrderResource
    {
        $this->authorize('update', $order);
        if ($action === 'cancel') {
            $this->authorize('cancel', $order);
        }
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
            'account' => ['nullable', 'string', 'max:40'],
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
        $this->authorize('voidPayment', Order::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new PaymentResource($payments->void($payment, $data['reason'])->load('creator'));
    }

    /**
     * Rules from Cấu hình: when staff may not change prices, a SKU line sells at its retail or wholesale price with
     * no discount (members with orders.price excepted); the sale time can be back-dated only when allowed.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardSettings(Request $request, array $data): void
    {
        $settings = TenantSettings::current();
        if (isset($data['placed_at']) && ! $settings->get('sales.allow_change_sale_time')) {
            throw ValidationException::withMessages(['placed_at' => 'Cấu hình chưa cho phép sửa thời gian bán.']);
        }
        if ($settings->get('pricing.staff_edit_price') || $request->user()->can('changePrice', Order::class)) {
            return;
        }
        if (! empty($data['promotions_off'])) {
            throw ValidationException::withMessages(['promotions_off' => 'Bạn không có quyền bỏ khuyến mãi trên đơn.']);
        }
        $lines = $data['lines'] ?? [];
        $variants = ProductVariant::query()->whereIn('ulid', array_filter(array_column($lines, 'variant_id')))->get()->keyBy('ulid');
        $subtotal = '0';
        foreach ($lines as $line) {
            $price = $line['unit_price'] ?? ($variants[$line['variant_id'] ?? '']->ref_price ?? 0);
            $subtotal = bcadd($subtotal, bcmul((string) $price, (string) ($line['quantity'] ?? 0), 2), 2);
        }
        // The customer group's discount is a shop policy, so it applies even for staff who may not discount by hand.
        if ((float) ($data['discount'] ?? 0) > 0 && bccomp((string) $data['discount'], $this->groupDiscount($data, $subtotal), 2) > 0) {
            throw ValidationException::withMessages(['discount' => 'Bạn không có quyền chiết khấu đơn hàng.']);
        }
        foreach ($lines as $i => $line) {
            if ((float) ($line['discount'] ?? 0) > 0) {
                throw ValidationException::withMessages(["lines.{$i}.discount" => 'Bạn không có quyền chiết khấu.']);
            }
            $variant = $variants[$line['variant_id'] ?? ''] ?? null;
            if (! $variant || ! isset($line['unit_price'])) {
                continue; // service lines have no list price; no price sent = the retail price
            }
            $allowed = array_filter([(string) $variant->ref_price, (string) $variant->wholesale_price], fn ($p) => (float) $p > 0);
            if (! collect($allowed)->contains(fn ($p) => bccomp((string) $line['unit_price'], $p, 2) === 0)) {
                throw ValidationException::withMessages(["lines.{$i}.unit_price" => "Bạn không có quyền sửa giá {$variant->sku}."]);
            }
        }
    }

    /**
     * Largest order discount the customer's group gives on this subtotal (rounded to the đồng), 0 without a group.
     *
     * @param  array<string, mixed>  $data
     */
    private function groupDiscount(array $data, string $subtotal): string
    {
        $percent = empty($data['customer_id']) ? null
            : Customer::query()->where('ulid', $data['customer_id'])->first()?->customerGroup?->discount_percent;

        return $percent ? (string) round((float) bcdiv(bcmul($subtotal, (string) $percent, 4), '100', 4)) : '0';
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
            ...VnAddress::rules('customer.'),
            'buyer_name' => ['nullable', 'string', 'max:150'],
            'buyer_phone' => ['nullable', 'string', 'max:20'],
            'shipping_address' => ['nullable', 'string', 'max:500'],
            ...VnAddress::rules('shipping_'),
            'shipping_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'promotions_off' => ['boolean'],
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
            // A line is either a SKU (variant_id) or a service with its own name (no stock).
            'lines.*.variant_id' => ['nullable', 'string', 'required_without:lines.*.name'],
            'lines.*.name' => ['nullable', 'string', 'max:255', 'required_without:lines.*.variant_id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }
}
