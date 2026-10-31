<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Data\ShopAuthorization;
use App\Modules\Marketplace\Connectors\Fake\FakeConnector;
use App\Modules\Marketplace\Exceptions\CredentialsMissing;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connects a real marketplace shop: the seller is sent to the marketplace to authorize our app, and comes back
 * to the SPA callback page with the marketplace's parameters plus our one-time "state". The state binds the
 * callback to the tenant and user who started it, server-side; nothing in the callback chooses the tenant.
 */
class ShopAuthorizationService
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly TenantContext $context,
        private readonly ListingService $listings,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{url: string, state: string} */
    public function start(string $marketplace, int $userId): array
    {
        $this->assertConnectable($marketplace);
        // The shop limit is checked when the shop is known (store): reconnecting an existing shop is always allowed.

        $state = Str::random(48);
        // The connector is asked first: an UNKNOWN connector throws before any state is stored.
        $url = $this->connectors->for($marketplace)->authorizationUrl($state, $this->redirectUri($marketplace));
        Cache::put($this->key($state), ['tenant_id' => $this->context->id(), 'user_id' => $userId, 'marketplace' => $marketplace], config('marketplaces.authorization_state_ttl'));

        return ['url' => $url, 'state' => $state];
    }

    /**
     * @param  array<string, string>  $query  callback parameters, including "state"
     */
    public function complete(string $marketplace, array $query, int $userId): MarketplaceAccount
    {
        $state = (string) ($query['state'] ?? '');
        $pending = $state !== '' ? Cache::pull($this->key($state)) : null; // one-time use
        if (! $pending || $pending['marketplace'] !== $marketplace || $pending['tenant_id'] !== $this->context->id() || $pending['user_id'] !== $userId) {
            throw ValidationException::withMessages(['state' => 'Phiên kết nối không hợp lệ hoặc đã hết hạn. Vui lòng bắt đầu lại.']);
        }
        $this->assertConnectable($marketplace);

        $auth = $this->connectors->for($marketplace)->completeAuthorization($query, $this->redirectUri($marketplace));
        $account = $this->store($marketplace, $auth);

        try {
            $this->listings->sync($account);
        } catch (\Throwable $e) {
            // The shop is connected; products can be synced again from the UI.
            report($e);
        }

        return $account;
    }

    private function store(string $marketplace, ShopAuthorization $auth): MarketplaceAccount
    {
        return DB::transaction(function () use ($marketplace, $auth) {
            DB::table('tenants')->where('id', $this->context->id())->lockForUpdate()->value('id');

            // A shop can belong to one tenant only. The lookup must ignore the tenant scope to see other tenants.
            $existing = MarketplaceAccount::withoutGlobalScopes()->where('marketplace', $marketplace)
                ->where('external_shop_id', $auth->externalShopId)->lockForUpdate()->first();
            if ($existing && $existing->tenant_id !== $this->context->id()) {
                throw ValidationException::withMessages(['shop' => 'Gian hàng này đã được kết nối với một doanh nghiệp khác.']);
            }
            if (! $existing || $existing->status === MarketplaceAccount::REVOKED) {
                $this->assertShopLimit();
            }

            $account = $existing ?? new MarketplaceAccount([
                'marketplace' => $marketplace,
                'external_shop_id' => $auth->externalShopId,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            ]);
            $account->forceFill([
                'shop_name' => $auth->shopName,
                'region' => $auth->region,
                'status' => MarketplaceAccount::CONNECTED,
                'last_error' => null,
                'consecutive_failures' => 0,
                'circuit_open_until' => null,
                'connected_at' => now(),
            ])->save();

            MarketplaceCredential::query()->updateOrCreate(['marketplace_account_id' => $account->id], [
                'access_token' => $auth->accessToken,
                'refresh_token' => $auth->refreshToken,
                'access_expires_at' => $auth->accessExpiresAt,
                'refresh_expires_at' => $auth->refreshExpiresAt,
                'scopes' => $auth->scopes,
            ]);
            $this->audit->record($existing ? 'marketplace.reconnected' : 'marketplace.connected', $account, ['marketplace' => $marketplace, 'shop' => $auth->shopName]);

            return $account;
        });
    }

    private function assertConnectable(string $marketplace): void
    {
        if ($marketplace === FakeConnector::MARKETPLACE || ! $this->connectors->has($marketplace)) {
            throw ValidationException::withMessages(['marketplace' => 'Sàn không hỗ trợ kết nối theo cách này.']);
        }
        $missing = array_keys(array_filter((array) config("marketplaces.credentials.{$marketplace}", []), fn ($v) => blank($v)));
        if ($missing !== [] || config("marketplaces.credentials.{$marketplace}") === null) {
            throw new CredentialsMissing($marketplace, $missing);
        }
    }

    private function assertShopLimit(): void
    {
        $this->context->entitlements()->assertWithin('limit.shops', MarketplaceAccount::query()->where('status', '!=', MarketplaceAccount::REVOKED)->count());
    }

    private function redirectUri(string $marketplace): string
    {
        return rtrim((string) config('app.url'), '/')."/marketplaces/{$marketplace}/callback";
    }

    private function key(string $state): string
    {
        return 'marketplace-auth:'.hash('sha256', $state);
    }

    /** Marketplaces whose app credentials are configured on this installation. */
    public static function configured(string $marketplace): bool
    {
        $values = config("marketplaces.credentials.{$marketplace}");

        return is_array($values) && $values !== [] && ! in_array(true, array_map(fn ($v) => blank($v), $values), true);
    }
}
