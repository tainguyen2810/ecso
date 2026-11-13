<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Connectors\Tiki\TikiClient;
use App\Modules\Marketplace\Connectors\Tiki\TikiConnector;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Connects a Tiki shop through the shop's own In-house app: asks Tiki for a token with the Client ID and Secret,
 * reads the shop (sellers/me), and stores the account. The secret is kept encrypted to ask for new tokens.
 * Order and stock sync stay off until they are verified on a real shop (see TikiConnector).
 */
class TikiConnectService
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function connect(string $clientId, string $clientSecret, ?Warehouse $warehouse): MarketplaceAccount
    {
        $client = new TikiClient(trim($clientId), trim($clientSecret));
        try {
            $token = $client->requestToken();
            $seller = $client->get('sellers/me');
        } catch (ConnectorException $e) {
            throw ValidationException::withMessages(['client_id' => $e->getMessage()]);
        }
        $sid = (string) ($seller['sid'] ?? '');
        if ($sid === '') {
            throw ValidationException::withMessages(['client_id' => 'Tiki không trả về thông tin shop. Kiểm tra ứng dụng đã được kết nối với shop trong Seller Center.']);
        }
        $name = mb_substr((string) ($seller['name'] ?? '') ?: 'Shop Tiki', 0, 100);

        return DB::transaction(function () use ($sid, $name, $clientId, $clientSecret, $token, $seller, $warehouse) {
            DB::table('tenants')->where('id', $this->context->id())->lockForUpdate()->value('id');
            $existing = MarketplaceAccount::withoutGlobalScopes()->where('marketplace', TikiConnector::MARKETPLACE)->where('external_shop_id', $sid)->lockForUpdate()->first();
            if ($existing && $existing->tenant_id !== $this->context->id()) {
                throw ValidationException::withMessages(['client_id' => 'Shop Tiki này đã được kết nối với một doanh nghiệp khác.']);
            }
            if (! $existing || $existing->status === MarketplaceAccount::REVOKED) {
                $this->context->entitlements()->assertWithin('limit.shops', MarketplaceAccount::query()->where('status', '!=', MarketplaceAccount::REVOKED)->count());
            }

            $account = $existing ?? new MarketplaceAccount(['marketplace' => TikiConnector::MARKETPLACE, 'external_shop_id' => $sid]);
            $account->forceFill([
                'shop_name' => $name,
                'region' => 'VN',
                'status' => MarketplaceAccount::CONNECTED,
                // Nothing to sync yet: orders and stock wait for verification on a real shop.
                'sync_enabled' => false,
                'warehouse_id' => $warehouse->id ?? $account->warehouse_id ?? Warehouse::query()->where('is_default', true)->value('id'),
                'settings' => array_merge(MarketplaceAccount::SYNC_DEFAULTS, (array) $account->settings, [
                    'client_id' => trim($clientId),
                    'operation_models' => array_values((array) ($seller['operation_models'] ?? [])),
                ]),
                'last_error' => null,
                'consecutive_failures' => 0,
                'circuit_open_until' => null,
                'connected_at' => now(),
                'orders_since' => $account->orders_since ?? now(),
            ])->save();

            MarketplaceCredential::query()->updateOrCreate(['marketplace_account_id' => $account->id], [
                'access_token' => $token['access_token'],
                'refresh_token' => trim($clientSecret),
                'access_expires_at' => $token['expires_at'],
                'scopes' => [],
            ]);
            $this->audit->record($existing ? 'marketplace.reconnected' : 'marketplace.connected', $account, ['marketplace' => TikiConnector::MARKETPLACE, 'shop' => $account->shop_name]);

            return $account;
        });
    }
}
