<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\OrderPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Point of sale helpers. Sales themselves go through SalesOrderController (stage = completed). */
class PosController extends Controller
{
    /**
     * Today's figures of the current cashier, to count the cash drawer at the end of a shift: money is grouped by
     * method and by the day it was received (paid_at), refunds subtracted.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('create', Order::class);
        $from = now()->startOfDay();
        $userId = $request->user()->id;

        $orders = Order::query()->where('marketplace', Order::SOURCE_MANUAL)->where('created_by', $userId)->where('placed_at', '>=', $from);
        $money = OrderPayment::query()->whereNull('voided_at')->where('created_by', $userId)->where('paid_at', '>=', $from)
            ->selectRaw("method, SUM(CASE WHEN kind = 'refund' THEN -amount ELSE amount END) as total")->groupBy('method')->pluck('total', 'method');

        return response()->json(['data' => [
            'orders' => (clone $orders)->where('status', '!=', OrderStatus::CANCELLED)->count(),
            'revenue' => (string) (clone $orders)->where('status', '!=', OrderStatus::CANCELLED)->sum('total'),
            'cancelled' => (clone $orders)->where('status', OrderStatus::CANCELLED)->count(),
            'by_method' => collect(OrderPayment::METHODS)->mapWithKeys(fn ($m) => [$m => bcadd((string) ($money[$m] ?? 0), '0', 2)]),
            'collected' => bcadd((string) $money->sum(), '0', 2),
            'since' => $from->toIso8601String(),
        ]]);
    }
}
