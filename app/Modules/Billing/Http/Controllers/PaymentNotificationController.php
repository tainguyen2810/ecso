<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Payments\GatewayRegistry;
use App\Modules\Billing\Payments\InvalidPaymentNotification;
use App\Modules\Billing\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-to-server payment notifications (IPN). Public: authenticity comes from the gateway signature, the
 * tenant from the invoice found by its transfer code. Bank transfer has no notifications (404).
 */
class PaymentNotificationController extends Controller
{
    public function __invoke(Request $request, string $gateway, GatewayRegistry $gateways, PaymentService $payments): JsonResponse
    {
        abort_unless($gateways->has($gateway), 404);

        try {
            $notification = $gateways->get($gateway)->parseNotification($request->getContent(), $request->headers->all());
        } catch (InvalidPaymentNotification) {
            return response()->json(['message' => 'Invalid notification'], 401);
        }
        abort_if($notification === null, 404);

        $invoice = Invoice::withoutGlobalScopes()->where('transfer_code', $notification->transferCode)->first();
        if (! $invoice) {
            return response()->json(['message' => 'Unknown invoice'], 404);
        }

        $result = $payments->record($gateway, $notification->transactionId, $invoice, $notification->amount, $notification->receivedAt, null, null, $notification->raw);

        return response()->json(['received' => true, 'duplicate' => $result['duplicate'], 'status' => $result['payment']->status]);
    }
}
