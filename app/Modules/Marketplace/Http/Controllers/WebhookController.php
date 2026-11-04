<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Services\WebhookReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public endpoint called by marketplaces. No session, no tenant header: the shop is resolved server-side. */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $marketplace, ConnectorRegistry $connectors, WebhookReceiver $receiver): JsonResponse
    {
        abort_unless($connectors->has($marketplace), 404);

        // A new Woo webhook is pinged once, unsigned; answering 200 keeps it active. Nothing is stored or processed.
        if ($marketplace === WooCommerceConnector::MARKETPLACE && WooCommerceConnector::isPing($request->getContent())) {
            return response()->json(['received' => true]);
        }

        $result = $receiver->receive($marketplace, $request->getContent(), $request->headers->all());

        if (! $result['accepted']) {
            return response()->json(['message' => 'Invalid webhook'], 401);
        }

        return response()->json(['received' => true, 'duplicate' => $result['duplicate']]);
    }
}
