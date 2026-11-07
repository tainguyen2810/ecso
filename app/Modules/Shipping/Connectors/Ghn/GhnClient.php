<?php

namespace App\Modules\Shipping\Connectors\Ghn;

use App\Modules\Shipping\Connectors\CarrierException;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GHN's public API (developer.ghn.vn): JSON over POST, headers Token (+ ShopId per pickup point). Every answer is
 * {code, message, data}; code 200 = success. The token is never logged.
 */
class GhnClient
{
    public const BASE = [
        CarrierAccount::PRODUCTION => 'https://online-gateway.ghn.vn',
        CarrierAccount::SANDBOX => 'https://dev-online-gateway.ghn.vn',
    ];

    public static function base(string $environment): string
    {
        return self::BASE[$environment] ?? self::BASE[CarrierAccount::PRODUCTION];
    }

    /**
     * @param  bool  $sideEffect  the call makes something at GHN: a lost answer is "uncertain", not a plain failure
     * @return mixed the answer's data
     */
    public function post(string $token, string $environment, string $path, array $body = [], ?string $shopId = null, bool $sideEffect = false): mixed
    {
        $headers = array_filter(['Token' => $token, 'ShopId' => $shopId], fn ($v) => $v !== null && $v !== '');
        try {
            $response = Http::withHeaders($headers)->acceptJson()->timeout((int) config('shipping.timeout'))
                ->post(self::base($environment).'/shiip/public-api'.$path, $body);
        } catch (ConnectionException $e) {
            Log::warning('ghn.unreachable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new CarrierException('GHN không trả lời kịp. Thử lại sau.', uncertain: $sideEffect);
        }
        $json = (array) $response->json();
        if ($response->status() === 401 || $response->status() === 403) {
            throw new CarrierException('GHN không nhận token này. Kiểm tra lại token API của shop.', authFailed: true);
        }
        if ($response->serverError()) {
            throw new CarrierException('GHN đang lỗi. Thử lại sau.', uncertain: $sideEffect);
        }
        if ((int) ($json['code'] ?? $response->status()) !== 200) {
            $message = trim((string) ($json['code_message_value'] ?? $json['message'] ?? 'GHN từ chối yêu cầu.'));
            Log::info('ghn.refused', ['path' => $path, 'code' => $json['code'] ?? null, 'code_message' => $json['code_message'] ?? null]);
            throw new CarrierException('GHN: '.mb_substr($message, 0, 220));
        }

        return $json['data'] ?? null;
    }
}
