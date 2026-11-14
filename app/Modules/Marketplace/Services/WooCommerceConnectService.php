<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Connectors\WooCommerce\WooClient;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Jobs\SyncListingsJob;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connects a WooCommerce store, the easy way or by hand:
 *
 *  1. start(): the user types the website; ECSO sends them to Woo's own approval screen
 *     ({site}/wc-auth/v1/authorize). One-time "state" (Woo's user_id parameter) binds the attempt to tenant + user.
 *  2. receiveKeys(): after "Phê duyệt", Woo POSTs the new REST keys to ECSO (server to server). They are only parked
 *     in the cache under the state; nothing in that request picks a tenant.
 *  3. complete(): Woo sends the user back; the SPA calls this with the state and the store is connected.
 *
 * connectWithKeys() is the fallback for stores whose security plugin blocks step 2: the user pastes the keys.
 */
class WooCommerceConnectService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{url: string, state: string} */
    public function start(string $website, ?Warehouse $warehouse, int $userId): array
    {
        $url = $this->normalize($website);
        $state = Str::random(48);
        Cache::put($this->key('pending', $state), [
            'tenant_id' => $this->context->id(), 'user_id' => $userId, 'url' => $url, 'warehouse_id' => $warehouse?->id,
        ], (int) config('marketplaces.woocommerce.authorize_ttl'));

        $app = rtrim((string) config('app.url'), '/');
        $query = http_build_query([
            'app_name' => 'ECSO',
            'scope' => 'read_write',
            'user_id' => $state,
            'return_url' => "{$app}/marketplaces/woocommerce/callback?state={$state}",
            'callback_url' => "{$app}/api/v1/woocommerce/auth-callback",
        ]);

        return ['url' => "{$url}/wc-auth/v1/authorize?{$query}", 'state' => $state];
    }

    /**
     * Woo's server-to-server delivery of the keys. Unknown or expired states are refused; keys are kept 15 minutes.
     *
     * @param  array<string, mixed>  $payload  {key_id, user_id, consumer_key, consumer_secret, key_permissions}
     */
    public function receiveKeys(array $payload): bool
    {
        $state = (string) ($payload['user_id'] ?? '');
        if ($state === '' || ! Cache::has($this->key('pending', $state))
            || blank($payload['consumer_key'] ?? null) || blank($payload['consumer_secret'] ?? null)) {
            return false;
        }

        Cache::put($this->key('keys', $state), [
            'consumer_key' => (string) $payload['consumer_key'],
            'consumer_secret' => (string) $payload['consumer_secret'],
            'permissions' => (string) ($payload['key_permissions'] ?? ''),
        ], (int) config('marketplaces.woocommerce.authorize_ttl'));

        return true;
    }

    /** @return array{account: MarketplaceAccount, checks: array<string, mixed>} */
    public function complete(string $state, int $userId): array
    {
        $pending = Cache::get($this->key('pending', $state));
        if (! $pending || $pending['tenant_id'] !== $this->context->id() || $pending['user_id'] !== $userId) {
            throw ValidationException::withMessages(['state' => 'Phiên kết nối không hợp lệ hoặc đã hết hạn. Vui lòng bắt đầu lại.']);
        }
        $keys = Cache::get($this->key('keys', $state));
        if (! $keys) {
            throw ValidationException::withMessages(['state' => 'Website chưa gửi khóa về ECSO. Có thể plugin bảo mật hoặc tường lửa của website chặn bước này; hãy dùng "Nhập khóa API thủ công".']);
        }
        Cache::forget($this->key('pending', $state));
        Cache::forget($this->key('keys', $state));

        $warehouse = $pending['warehouse_id'] ? Warehouse::query()->find($pending['warehouse_id']) : null;

        return $this->connect($pending['url'], $keys['consumer_key'], $keys['consumer_secret'], $warehouse);
    }

    /** @return array{account: MarketplaceAccount, checks: array<string, mixed>} */
    public function connectWithKeys(string $website, string $key, string $secret, ?Warehouse $warehouse): array
    {
        return $this->connect($this->normalize($website), trim($key), trim($secret), $warehouse);
    }

    /**
     * Probes the API with the keys, stores the store, registers the webhooks and queues the product import.
     *
     * @return array{account: MarketplaceAccount, checks: array<string, mixed>}
     */
    private function connect(string $url, string $key, string $secret, ?Warehouse $warehouse): array
    {
        [$client, $status] = $this->probe(new WooClient($url, $key, $secret));
        $home = (string) ($status['environment']['home_url'] ?? $url);
        $storeId = WooClient::storeId($home);
        $checks = [
            'https' => str_starts_with($url, 'https://'),
            'woocommerce_version' => $status['environment']['version'] ?? null,
            'products' => $this->total($client, 'products'),
            'orders' => $this->total($client, 'orders'),
        ];

        $account = $this->store($storeId, $url, $client, $key, $secret, $warehouse, (string) ($status['settings']['store_name'] ?? '') ?: WooClient::host($home));

        try {
            $checks['webhooks'] = $this->registerWebhooks($account, $client);
        } catch (ConnectorException $e) {
            $account->forceFill(['last_error' => $e->getMessage()])->save();
            throw ValidationException::withMessages(['website' => $e->authExpired
                ? 'Khóa API chỉ có quyền Đọc. ECSO cần quyền Đọc/Ghi để nhận đơn và cập nhật tồn kho.'
                : 'Không đăng ký được webhook nhận đơn: '.$e->getMessage()]);
        }

        SyncListingsJob::dispatch($account->id)->afterCommit();

        return ['account' => $account, 'checks' => $checks];
    }

    /**
     * Calls system_status with Basic auth, then with query-string auth (hosts that strip Authorization).
     *
     * @return array{0: WooClient, 1: array<string, mixed>}
     */
    private function probe(WooClient $client): array
    {
        $error = 'Không kết nối được website.';
        foreach ([WooClient::AUTH_BASIC, WooClient::AUTH_QUERY] as $auth) {
            try {
                $c = $client->withAuth($auth);

                return [$c, $c->get('system_status', ['_fields' => 'environment,settings'])];
            } catch (ConnectorException $e) {
                $error = $e->getMessage();
                if (! $e->authExpired) {
                    break;
                }
            }
        }

        throw ValidationException::withMessages(['website' => $error]);
    }

    private function total(WooClient $client, string $resource): ?int
    {
        try {
            $header = $client->send('GET', $resource, ['per_page' => 1, '_fields' => 'id'])->header('X-WP-Total');

            return $header !== '' ? (int) $header : null;
        } catch (ConnectorException) {
            return null;
        }
    }

    private function store(string $storeId, string $url, WooClient $client, string $key, string $secret, ?Warehouse $warehouse, string $name): MarketplaceAccount
    {
        return DB::transaction(function () use ($storeId, $url, $client, $key, $secret, $warehouse, $name) {
            DB::table('tenants')->where('id', $this->context->id())->lockForUpdate()->value('id');

            $existing = MarketplaceAccount::withoutGlobalScopes()->where('marketplace', WooCommerceConnector::MARKETPLACE)
                ->where('external_shop_id', $storeId)->lockForUpdate()->first();
            if ($existing && $existing->tenant_id !== $this->context->id()) {
                throw ValidationException::withMessages(['website' => 'Website này đã được kết nối với một doanh nghiệp khác.']);
            }
            if (! $existing || $existing->status === MarketplaceAccount::REVOKED) {
                $this->context->entitlements()->assertWithin('limit.shops', MarketplaceAccount::query()->where('status', '!=', MarketplaceAccount::REVOKED)->count());
            }

            $account = $existing ?? new MarketplaceAccount([
                'marketplace' => WooCommerceConnector::MARKETPLACE,
                'external_shop_id' => $storeId,
                'shop_name' => mb_substr($name, 0, 100),
            ]);
            $account->forceFill([
                'region' => 'VN',
                'base_url' => $url,
                'status' => MarketplaceAccount::CONNECTED,
                'sync_enabled' => true,
                'warehouse_id' => $warehouse->id ?? $account->warehouse_id ?? Warehouse::query()->where('is_default', true)->value('id'),
                // A5: a store connected for the first time goes through the setup steps before any stock is pushed.
                'settings' => array_merge(MarketplaceAccount::SYNC_DEFAULTS, (array) $account->settings, ['auth' => $client->auth], $existing ? [] : ['setup' => 'pending']),
                'last_error' => null,
                'consecutive_failures' => 0,
                'circuit_open_until' => null,
                'connected_at' => now(),
                // Orders are synced from the moment the store is first connected; older ones are left on the store.
                'orders_since' => $account->orders_since ?? now(),
            ])->save();

            MarketplaceCredential::query()->updateOrCreate(['marketplace_account_id' => $account->id], [
                'access_token' => $key,
                'refresh_token' => $secret,
                'scopes' => ['read_write'],
            ]);
            $this->audit->record($existing ? 'marketplace.reconnected' : 'marketplace.connected', $account, ['marketplace' => WooCommerceConnector::MARKETPLACE, 'shop' => $account->shop_name]);

            return $account;
        });
    }

    /**
     * (Re)creates ECSO's webhooks on the store with a fresh secret. Old ECSO webhooks are removed first so a
     * reconnect never leaves duplicates.
     *
     * @return array<int, string> registered topics
     */
    public function registerWebhooks(MarketplaceAccount $account, WooClient $client): array
    {
        $this->removeWebhooks($account, $client);

        $secret = Str::random(40);
        $delivery = $this->deliveryUrl();
        $ids = [];
        // The secret must be stored before Woo pings / delivers, or the first deliveries would be refused.
        MarketplaceCredential::query()->where('marketplace_account_id', $account->id)->firstOrFail()->forceFill(['webhook_secret' => $secret])->save();

        foreach (WooCommerceConnector::WEBHOOK_TOPICS as $topic) {
            $created = $client->post('webhooks', [
                'name' => "ECSO {$topic}",
                'topic' => $topic,
                'delivery_url' => $delivery,
                'secret' => $secret,
                'status' => 'active',
            ]);
            $ids[] = (int) ($created['id'] ?? 0);
        }

        $account->forceFill(['settings' => array_merge((array) $account->settings, ['webhook_ids' => array_values(array_filter($ids))])])->save();

        return WooCommerceConnector::WEBHOOK_TOPICS;
    }

    /**
     * Woo turns a webhook off after several failed deliveries (and stores connected before a topic existed lack
     * it). This finds ECSO's webhooks on the store, turns disabled/paused ones back on and creates missing topics
     * with the stored secret. The outcome is kept on the account for the store page.
     *
     * @return array{ok: bool, checked_at: string, missing: array<int, string>, reactivated: array<int, string>, error: string|null}
     */
    public function checkWebhooks(MarketplaceAccount $account): array
    {
        $delivery = $this->deliveryUrl();
        $health = ['ok' => false, 'checked_at' => now()->toIso8601String(), 'missing' => [], 'reactivated' => [], 'error' => null];

        try {
            $client = WooClient::forAccount($account);
            $secret = MarketplaceCredential::query()->where('marketplace_account_id', $account->id)->first()?->webhook_secret;
            if (blank($secret)) {
                // No secret: nothing could be verified; registering again makes a fresh one.
                $this->registerWebhooks($account, $client);
                $health['missing'] = WooCommerceConnector::WEBHOOK_TOPICS;
            } else {
                $ours = collect((array) $client->get('webhooks', ['per_page' => 100]))
                    ->filter(fn ($w) => rtrim((string) ($w['delivery_url'] ?? ''), '/') === $delivery)->keyBy('topic');
                $ids = [];
                foreach (WooCommerceConnector::WEBHOOK_TOPICS as $topic) {
                    $hook = $ours->get($topic);
                    if ($hook === null) {
                        $hook = $client->post('webhooks', ['name' => "ECSO {$topic}", 'topic' => $topic, 'delivery_url' => $delivery, 'secret' => $secret, 'status' => 'active']);
                        $health['missing'][] = $topic;
                    } elseif (($hook['status'] ?? 'active') !== 'active') {
                        $hook = $client->put('webhooks/'.(int) $hook['id'], ['status' => 'active']);
                        $health['reactivated'][] = $topic;
                    }
                    $ids[] = (int) ($hook['id'] ?? 0);
                }
                $account->forceFill(['settings' => array_merge((array) $account->settings, ['webhook_ids' => array_values(array_filter($ids))])]);
            }
            $health['ok'] = true;
        } catch (ConnectorException $e) {
            $health['error'] = mb_substr($e->getMessage(), 0, 300);
        }

        $account->forceFill(['settings' => array_merge((array) $account->settings, ['webhook_health' => $health])])->save();

        return $health;
    }

    private function deliveryUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/webhooks/'.WooCommerceConnector::MARKETPLACE;
    }

    /** Best effort: a store that is gone or refuses must not block a disconnect. */
    public function removeWebhooks(MarketplaceAccount $account, ?WooClient $client = null): void
    {
        $ids = (array) ($account->settings['webhook_ids'] ?? []);
        if ($ids === []) {
            return;
        }
        try {
            $client ??= WooClient::forAccount($account);
            foreach ($ids as $id) {
                try {
                    $client->delete('webhooks/'.(int) $id);
                } catch (ConnectorException) {
                    // already deleted on the store
                }
            }
        } catch (ConnectorException) {
            // no keys any more
        }
        $account->forceFill(['settings' => array_merge((array) $account->settings, ['webhook_ids' => []])])->save();
    }

    private function normalize(string $website): string
    {
        try {
            return WooClient::normalizeUrl($website);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['website' => $e->getMessage()]);
        }
    }

    private function key(string $kind, string $state): string
    {
        return "woocommerce-auth:{$kind}:".hash('sha256', $state);
    }
}
