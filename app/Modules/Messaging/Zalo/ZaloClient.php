<?php

namespace App\Modules\Messaging\Zalo;

use App\Modules\Messaging\Models\ZaloAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Zalo Official Account and ZNS open APIs (developers.zalo.me/docs/zalo-notification-service).
 *
 * Access tokens last about 25 hours and come from a refresh token that Zalo replaces on every use (a refresh token
 * works once), so a refresh locks the account row and saves the new pair before anything else. Calls carry the token
 * in the access_token header and answer {error: 0, data} on success. Keys and tokens are never logged.
 */
class ZaloClient
{
    public const OAUTH = 'https://oauth.zaloapp.com/v4/oa/access_token';

    public const OPENAPI = 'https://openapi.zalo.me';

    public const BUSINESS = 'https://business.openapi.zalo.me';

    /** Answers that mean the token is no longer good. */
    private const TOKEN_ERRORS = [-124, -216, -14014, -14016, -14019];

    /**
     * Swaps a refresh token for a new access token and refresh token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function exchange(string $appId, string $secretKey, string $refreshToken): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->withHeaders(['secret_key' => $secretKey])
                ->post(self::OAUTH, ['app_id' => $appId, 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
        } catch (ConnectionException $e) {
            Log::warning('zalo.unreachable', ['path' => 'oauth', 'error' => $e->getMessage()]);
            throw new ZaloException('Zalo không trả lời kịp. Thử lại sau.');
        }
        $json = (array) $response->json();
        if (! filled($json['access_token'] ?? null) || ! filled($json['refresh_token'] ?? null)) {
            $why = (string) ($json['error_description'] ?? $json['error_name'] ?? $json['message'] ?? '');
            throw new ZaloException('Zalo không nhận App ID / Secret key / Refresh token này'.($why !== '' ? " ({$why})" : '').'. Lấy refresh token mới rồi thử lại.', authFailed: true);
        }

        return ['access_token' => (string) $json['access_token'], 'refresh_token' => (string) $json['refresh_token'], 'expires_in' => (int) ($json['expires_in'] ?? 90000)];
    }

    /** A valid access token for the account, refreshed (and the new refresh token saved) when it is about to expire. */
    public function token(ZaloAccount $account, bool $fresh = false): string
    {
        $secrets = (array) $account->secrets;
        if (! $fresh && filled($secrets['access_token'] ?? null) && $account->access_expires_at?->isAfter(now()->addMinutes(5))) {
            return (string) $secrets['access_token'];
        }

        return DB::transaction(function () use ($account, $fresh) {
            /** @var ZaloAccount $locked */
            $locked = ZaloAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $secrets = (array) $locked->secrets;
            // Someone else refreshed while we waited for the lock.
            if (! $fresh && filled($secrets['access_token'] ?? null) && $locked->access_expires_at?->isAfter(now()->addMinutes(5))) {
                $account->setRawAttributes($locked->getAttributes(), true);

                return (string) $secrets['access_token'];
            }
            try {
                $pair = $this->exchange($locked->app_id, (string) ($secrets['secret_key'] ?? ''), (string) ($secrets['refresh_token'] ?? ''));
            } catch (ZaloException $e) {
                $locked->forceFill(['status' => ZaloAccount::ERROR, 'last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
                throw $e;
            }
            $locked->forceFill([
                'secrets' => [...$secrets, 'access_token' => $pair['access_token'], 'refresh_token' => $pair['refresh_token']],
                'access_expires_at' => now()->addSeconds(max(600, $pair['expires_in'] - 300)),
                'status' => ZaloAccount::CONNECTED,
                'last_error' => null,
            ])->save();
            $account->setRawAttributes($locked->getAttributes(), true);

            return $pair['access_token'];
        });
    }

    /** @return array<string, mixed> the answer's data */
    public function get(ZaloAccount $account, string $url, array $query = []): array
    {
        return $this->call($account, 'GET', $url, $query);
    }

    /** @return array<string, mixed> the answer's data */
    public function post(ZaloAccount $account, string $url, array $body): array
    {
        return $this->call($account, 'POST', $url, $body);
    }

    /** @return array<string, mixed> */
    private function call(ZaloAccount $account, string $method, string $url, array $payload, bool $retried = false): array
    {
        $token = $this->token($account, $retried);
        try {
            $request = Http::acceptJson()->timeout(20)->withHeaders(['access_token' => $token]);
            $response = $method === 'GET' ? $request->get($url, $payload) : $request->asJson()->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('zalo.unreachable', ['path' => parse_url($url, PHP_URL_PATH), 'error' => $e->getMessage()]);
            throw new ZaloException('Zalo không trả lời kịp. Thử lại sau.');
        }
        $json = (array) $response->json();
        $error = (int) ($json['error'] ?? ($response->successful() ? 0 : $response->status()));
        if (in_array($error, self::TOKEN_ERRORS, true) && ! $retried) {
            return $this->call($account, $method, $url, $payload, true);
        }
        if ($error !== 0) {
            throw new ZaloException(self::explain($error, (string) ($json['message'] ?? '')), authFailed: in_array($error, self::TOKEN_ERRORS, true), code: $error);
        }

        return (array) ($json['data'] ?? []);
    }

    /** The common Zalo error codes in words the shop understands; any other keeps Zalo's own message. */
    public static function explain(int $code, string $message): string
    {
        return match ($code) {
            -108 => 'Số điện thoại không hợp lệ.',
            -118 => 'Số điện thoại chưa dùng Zalo.',
            -124, -216, -14014, -14016, -14019 => 'Phiên kết nối Zalo hết hạn. Vào Kết nối dịch vụ → Zalo để kết nối lại.',
            default => 'Zalo báo lỗi '.$code.($message !== '' ? ": {$message}" : '').'.',
        };
    }
}
