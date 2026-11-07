<?php

namespace App\Modules\Shipping\Connectors\ViettelPost;

use App\Modules\Shipping\Connectors\CarrierException;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Viettel Post's partner API: JSON, header "Token". Most answers are {status, error, message, data} with status 200 =
 * success (HTTP stays 200 on refusals); price lists come back without that envelope. The token is never logged.
 */
class ViettelPostClient
{
    public const BASE = [
        CarrierAccount::PRODUCTION => 'https://partner.viettelpost.vn',
        CarrierAccount::SANDBOX => 'https://partnerdev.viettelpost.vn',
    ];

    public const PRINT_BASE = [
        CarrierAccount::PRODUCTION => 'https://digitalize.viettelpost.vn/DigitalizePrint/report.do',
        CarrierAccount::SANDBOX => 'https://dev-print.viettelpost.vn/DigitalizePrint/report.do',
    ];

    public static function base(string $environment): string
    {
        return self::BASE[$environment] ?? self::BASE[CarrierAccount::PRODUCTION];
    }

    /**
     * @param  bool  $sideEffect  the call makes something at Viettel Post: a lost answer is "uncertain"
     * @return array{data: mixed, message: string}
     */
    public function call(string $method, ?string $token, string $environment, string $path, array $body = [], bool $sideEffect = false): array
    {
        $request = Http::acceptJson()->timeout((int) config('shipping.timeout'))->withHeaders(array_filter(['Token' => $token]));
        try {
            $response = $method === 'GET'
                ? $request->get(self::base($environment).$path, $body)
                : $request->post(self::base($environment).$path, $body);
        } catch (ConnectionException $e) {
            Log::warning('viettelpost.unreachable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new CarrierException('Viettel Post không trả lời kịp. Thử lại sau.', uncertain: $sideEffect);
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new CarrierException('Viettel Post không nhận tài khoản này. Kết nối lại bằng số điện thoại và mật khẩu Viettel Post.', authFailed: true);
        }
        if ($response->serverError()) {
            throw new CarrierException('Viettel Post đang lỗi. Thử lại sau.', uncertain: $sideEffect);
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new CarrierException('Viettel Post trả lời không đúng định dạng.', uncertain: $sideEffect);
        }
        // Price lists are a bare list (or {RESULT: [...]}) without the usual envelope.
        if (! array_key_exists('status', $json) || ! array_key_exists('error', $json)) {
            return ['data' => $json, 'message' => ''];
        }
        $message = trim((string) ($json['message'] ?? ''));
        if ((int) $json['status'] !== 200 || $json['error'] === true) {
            Log::info('viettelpost.refused', ['path' => $path, 'status' => $json['status'], 'message' => mb_substr($message, 0, 200)]);
            $auth = (int) $json['status'] === 401 || str_contains(mb_strtolower($message), 'token');
            throw new CarrierException(
                $auth ? 'Viettel Post không nhận phiên đăng nhập này. Kết nối lại bằng số điện thoại và mật khẩu Viettel Post.' : 'Viettel Post: '.mb_substr($message !== '' ? $message : 'từ chối yêu cầu.', 0, 220),
                authFailed: $auth,
            );
        }

        return ['data' => $json['data'] ?? null, 'message' => $message];
    }
}
