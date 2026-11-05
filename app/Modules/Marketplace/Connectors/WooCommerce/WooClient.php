<?php

namespace App\Modules\Marketplace\Connectors\WooCommerce;

use App\Modules\Marketplace\Domain\SyncErrorCode;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client of the WooCommerce REST API v3 (https://woocommerce.github.io/woocommerce-rest-api-docs/).
 * Over HTTPS, Woo accepts the consumer key/secret as HTTP Basic auth; some hosts strip the Authorization header,
 * so a store can be switched to the documented query-string form ("query"), which is detected while connecting.
 */
class WooClient
{
    public const AUTH_BASIC = 'basic';

    public const AUTH_QUERY = 'query';

    public function __construct(
        public readonly string $baseUrl,
        private readonly string $key,
        private readonly string $secret,
        public readonly string $auth = self::AUTH_BASIC,
    ) {}

    public static function forAccount(MarketplaceAccount $account): self
    {
        $credential = MarketplaceCredential::query()->withoutGlobalScopes()->where('marketplace_account_id', $account->id)->first();
        if (! $credential || blank($credential->access_token) || blank($account->base_url)) {
            throw new ConnectorException('Website chưa có khóa API. Vui lòng kết nối lại.', temporary: false, authExpired: true);
        }

        return new self($account->base_url, (string) $credential->access_token, (string) $credential->refresh_token, $account->settings['auth'] ?? self::AUTH_BASIC);
    }

    public function withAuth(string $auth): self
    {
        return new self($this->baseUrl, $this->key, $this->secret, $auth);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     */
    public function send(string $method, string $path, array $query = [], ?array $json = null): Response
    {
        $url = rtrim($this->baseUrl, '/').'/wp-json/wc/v3/'.ltrim($path, '/');
        $request = Http::acceptJson()->timeout((int) config('marketplaces.woocommerce.timeout', 25))
            ->withUserAgent('ECSO/1.0 (+WooCommerce connector)');

        if ($this->auth === self::AUTH_QUERY) {
            $query += ['consumer_key' => $this->key, 'consumer_secret' => $this->secret];
        } else {
            $request = $request->withBasicAuth($this->key, $this->secret);
        }

        try {
            $response = $request->send($method, $url.($query !== [] ? '?'.http_build_query($query) : ''), $json !== null ? ['json' => $json] : []);
        } catch (ConnectionException $e) {
            throw new ConnectorException('Không gọi được website: '.self::host($this->baseUrl).' không phản hồi.', errorCode: SyncErrorCode::NETWORK_TIMEOUT);
        }

        if ($response->successful()) {
            return $response;
        }

        $message = (string) ($response->json('message') ?? '');
        $code = (string) ($response->json('code') ?? '');

        throw match (true) {
            in_array($response->status(), [401, 403], true) => new ConnectorException(
                'Website từ chối khóa API'.($message !== '' ? ': '.strip_tags($message) : '').'. Kiểm tra khóa còn hiệu lực và có quyền Đọc/Ghi.',
                temporary: false, authExpired: true, errorCode: $response->status() === 403 ? SyncErrorCode::PERMISSION_DENIED : SyncErrorCode::TOKEN_EXPIRED,
            ),
            $response->status() === 404 && $code === 'rest_no_route' => new ConnectorException('Website chưa bật WooCommerce REST API (không tìm thấy /wp-json/wc/v3).', temporary: false, errorCode: SyncErrorCode::PERMISSION_DENIED),
            $response->status() === 404 => new ConnectorException('Không tìm thấy trên website'.($message !== '' ? ': '.strip_tags($message) : '.'), temporary: false, notFound: true),
            $response->status() === 429 || $response->serverError() => new ConnectorException("Website đang bận (HTTP {$response->status()}), sẽ thử lại.", errorCode: $response->status() === 429 ? SyncErrorCode::RATE_LIMITED : SyncErrorCode::API_UNAVAILABLE),
            default => new ConnectorException('Website trả lỗi'.($message !== '' ? ': '.strip_tags($message) : " HTTP {$response->status()}"), temporary: false),
        };
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return (array) $this->send('GET', $path, $query)->json();
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<mixed>
     */
    public function put(string $path, array $json): array
    {
        return (array) $this->send('PUT', $path, [], $json)->json();
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<mixed>
     */
    public function post(string $path, array $json): array
    {
        return (array) $this->send('POST', $path, [], $json)->json();
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $path, ['force' => 'true']);
    }

    /**
     * Normalised identity of a store: host and path, lower case, without scheme or trailing slash. Woo sends the
     * same value (home_url) in the X-WC-Webhook-Source header, so webhooks are matched to the store with it.
     */
    public static function storeId(string $url): string
    {
        $parts = parse_url(trim($url));
        $id = strtolower(($parts['host'] ?? '').rtrim($parts['path'] ?? '', '/'));

        return strlen($id) <= 64 ? $id : substr($id, 0, 31).'~'.substr(hash('sha256', $id), 0, 32);
    }

    /**
     * DNS lookup used by normalizeUrl; tests swap it so they never depend on the runner's DNS.
     *
     * @var (\Closure(string): array<int, string>)|null
     */
    public static ?\Closure $resolver = null;

    public static function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?? $url);
    }

    /**
     * Normalises what a person typed ("itso.vn", "https://itso.vn/shop/") to "https://itso.vn/shop" and refuses
     * addresses that must never be called from the server (plain HTTP, IPs and hosts of private networks).
     *
     * @throws \InvalidArgumentException with a message for the user
     */
    public static function normalizeUrl(string $input): string
    {
        $input = trim($input);
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://'.$input;
        }
        $parts = parse_url($input);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($parts === false || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Địa chỉ website không hợp lệ.');
        }
        if (strtolower((string) $parts['scheme']) !== 'https' && ! config('marketplaces.woocommerce.allow_http')) {
            throw new \InvalidArgumentException('Website phải chạy HTTPS (https://...). WooCommerce chỉ cho kết nối an toàn qua HTTPS.');
        }
        if (! config('marketplaces.woocommerce.allow_private_hosts')) {
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::$resolver ?? fn (string $h) => gethostbynamel($h) ?: [])($host);
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new \InvalidArgumentException('Địa chỉ website trỏ vào mạng nội bộ, không kết nối được.');
                }
            }
            if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
                throw new \InvalidArgumentException('Địa chỉ website trỏ vào mạng nội bộ, không kết nối được.');
            }
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower((string) $parts['scheme']).'://'.$host.$port.rtrim($parts['path'] ?? '', '/');
    }
}
