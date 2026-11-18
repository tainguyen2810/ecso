<?php

namespace App\Modules\Marketplace\Connectors\EzChat;

use App\Modules\Marketplace\Domain\SyncErrorCode;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Shopee and TikTok Shop through EzChat (EZCHAT_URL): EzChat keeps the shops' tokens, the partner app keys and the
 * one push URL each marketplace allows, and signs every call. ECSO keeps only the share token EzChat gave for the
 * shop (stored as the shop's access token).
 *
 * Every request is signed with EZCHAT_SECRET (the same value as EzChat's BANHANG_SECRET):
 * X-Banhang-Signature = hex(hmac_sha256(secret, timestamp + "." + path + "." + raw body)). Pushes EzChat sends on
 * are signed the same way (X-EzChat-Timestamp / X-EzChat-Signature).
 */
class EzChatGateway
{
    private const SKEW = 300;

    public static function configured(): bool
    {
        return filled(config('marketplaces.ezchat.url')) && filled(config('marketplaces.ezchat.secret'));
    }

    /** The EzChat page where the seller picks the shop to share; EzChat returns to $redirectUri with code + state. */
    public function connectUrl(string $type, string $state, string $redirectUri): string
    {
        return $this->base().'/connect/banhang?'.http_build_query(['type' => $type, 'state' => $state, 'redirect' => $redirectUri]);
    }

    /**
     * EzChat answers {token, shop: {type, shopId, name, region}, workspace: {id, name}}; read defensively.
     *
     * @return array<string, mixed>
     */
    public function exchange(string $code): array
    {
        return $this->post('/api/banhang/gw/exchange', ['code' => $code]);
    }

    /**
     * One marketplace API call on the shop. Returns Shopee's whole answer, or TikTok's "data".
     *
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    public function call(MarketplaceAccount $account, string $method, string $path, array $query = [], ?array $body = null): array
    {
        $payload = ['method' => $method, 'path' => $path, 'query' => array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $query)];
        if ($body !== null) {
            $payload['body'] = $body;
        }

        return (array) ($this->post('/api/banhang/gw/call', $payload, $this->token($account))['data'] ?? []);
    }

    /** The shop was disconnected in ECSO: EzChat forgets the share. Errors are ignored (the share may be gone). */
    public function revoke(MarketplaceAccount $account): void
    {
        try {
            $this->post('/api/banhang/gw/revoke', [], $this->token($account));
        } catch (ConnectorException) {
        }
    }

    /** @param  array<string, mixed>  $headers  as $request->headers->all() */
    public function verifyPush(string $path, string $body, array $headers): bool
    {
        if (! self::configured()) {
            return false;
        }
        $headers = array_change_key_case($headers);
        $ts = (string) $this->header($headers, 'x-ezchat-timestamp');
        $signature = (string) $this->header($headers, 'x-ezchat-signature');
        if ($ts === '' || $signature === '' || ! ctype_digit($ts) || abs(time() - (int) $ts) > self::SKEW) {
            return false;
        }

        return hash_equals($this->sign("{$ts}.{$path}.{$body}"), $signature);
    }

    private function token(MarketplaceAccount $account): string
    {
        $token = MarketplaceCredential::query()->withoutGlobalScopes()->where('marketplace_account_id', $account->id)->value('access_token');
        if (blank($token)) {
            throw new ConnectorException('Shop chưa được chia sẻ từ EzChat. Vui lòng kết nối lại.', temporary: false, authExpired: true, errorCode: SyncErrorCode::TOKEN_EXPIRED);
        }

        return (string) $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, ?string $token = null): array
    {
        if (! self::configured()) {
            throw new ConnectorException('Chưa cấu hình kết nối EzChat (EZCHAT_URL, EZCHAT_SECRET).', temporary: false, errorCode: SyncErrorCode::UNSUPPORTED);
        }
        $raw = json_encode($payload === [] ? new \stdClass : $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ts = (string) time();
        $request = Http::withHeaders([
            'X-Banhang-Timestamp' => $ts,
            'X-Banhang-Signature' => $this->sign("{$ts}.{$path}.{$raw}"),
        ])->acceptJson()->timeout((int) config('marketplaces.ezchat.timeout', 30))->withUserAgent('ECSO/1.0 (+EzChat gateway)');
        if ($token !== null) {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->withBody($raw, 'application/json')->post($this->base().$path);
        } catch (ConnectionException $e) {
            throw new ConnectorException('Không kết nối được EzChat: '.$e->getMessage(), errorCode: SyncErrorCode::NETWORK_TIMEOUT);
        }

        $json = (array) $response->json();
        if ($response->successful()) {
            return (array) ($json['data'] ?? $json);
        }

        $error = (array) ($json['error'] ?? []);
        $code = (string) ($error['code'] ?? '');
        $message = (string) ($error['message'] ?? "EzChat trả về HTTP {$response->status()}");
        $details = (array) ($error['details'] ?? []);

        throw match (true) {
            $code === 'MARKETPLACE_ERROR' && ! empty($details['permission']) => new ConnectorException("App EzChat chưa được sàn cấp quyền cho thao tác này: {$message}", temporary: false, errorCode: SyncErrorCode::PERMISSION_DENIED),
            $code === 'MARKETPLACE_ERROR' && ! empty($details['auth']) => new ConnectorException("Sàn từ chối quyền truy cập của shop: {$message}. Hãy cấp quyền lại cho shop trong EzChat.", temporary: false, authExpired: true, errorCode: SyncErrorCode::TOKEN_EXPIRED),
            $code === 'MARKETPLACE_ERROR' && preg_match('/not.?found|not exist|không tồn tại/i', $message) === 1 => new ConnectorException($message, temporary: false, notFound: true, errorCode: SyncErrorCode::NOT_FOUND),
            $code === 'MARKETPLACE_ERROR' && preg_match('/rate|limit|too many|frequen/i', $message) === 1 => new ConnectorException($message, errorCode: SyncErrorCode::RATE_LIMITED),
            $code === 'MARKETPLACE_ERROR' => new ConnectorException($message, temporary: true, errorCode: SyncErrorCode::API_UNAVAILABLE),
            $code === 'BANHANG_NOT_SHARED', $response->status() === 401 => new ConnectorException('Shop không còn được chia sẻ từ EzChat. Vui lòng kết nối lại.', temporary: false, authExpired: true, errorCode: SyncErrorCode::TOKEN_EXPIRED),
            $code === 'SHOP_DISABLED' => new ConnectorException('Shop đang tắt trong EzChat.', temporary: false, errorCode: SyncErrorCode::PERMISSION_DENIED),
            $code === 'BANHANG_CODE' => new ConnectorException('Mã kết nối đã hết hạn. Vui lòng kết nối lại.', temporary: false, errorCode: SyncErrorCode::INVALID_DATA),
            $response->status() === 429 => new ConnectorException('EzChat đang giới hạn số lượt gọi.', errorCode: SyncErrorCode::RATE_LIMITED),
            $response->status() === 403 => new ConnectorException($message, temporary: false, errorCode: SyncErrorCode::PERMISSION_DENIED),
            $response->serverError() => new ConnectorException($message, errorCode: SyncErrorCode::API_UNAVAILABLE),
            default => new ConnectorException($message, temporary: false, errorCode: SyncErrorCode::INVALID_DATA),
        };
    }

    private function sign(string $text): string
    {
        return hash_hmac('sha256', $text, (string) config('marketplaces.ezchat.secret'));
    }

    private function base(): string
    {
        return rtrim((string) config('marketplaces.ezchat.url'), '/');
    }

    /** @param  array<string, mixed>  $headers */
    private function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }
}
