<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Connectors\CarrierRegistry;
use App\Modules\Shipping\Models\CarrierAccount;
use App\Modules\Shipping\Services\CarrierEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Called by carriers (no session, no tenant header). The long random token in the URL picks the account, and with it
 * the business; carriers like GHN do not sign their calls. Always answers 200 once the token is known, so one bad
 * report never holds back the carrier's next ones (GHN delivers per parcel, in order).
 */
class CarrierWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, CarrierRegistry $carriers, CarrierEventApplier $applier, TenantContext $context): JsonResponse
    {
        $account = CarrierAccount::query()->withoutGlobalScopes()->where('webhook_token', $token)->first();
        if ($account === null || ! $carriers->has($account->carrier) || $account->status === CarrierAccount::DISCONNECTED) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $context->set(Tenant::query()->findOrFail($account->tenant_id));
        try {
            $new = $applier->apply($account, $carriers->get($account->carrier)->parseWebhook($account, $request));
        } catch (Throwable $e) {
            Log::error('carrier.webhook_failed', ['carrier' => $account->carrier, 'error' => $e->getMessage()]);
            $new = 0;
        } finally {
            $context->clear();
        }

        return response()->json(['received' => true, 'new' => $new]);
    }
}
