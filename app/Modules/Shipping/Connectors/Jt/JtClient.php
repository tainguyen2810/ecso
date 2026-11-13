<?php

namespace App\Modules\Shipping\Connectors\Jt;

use App\Modules\Shipping\Connectors\CarrierException;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * J&T Express Vietnam open platform (the JMS platform J&T uses in several countries): a form POST with one field,
 * "bizContent" (JSON), and the headers apiAccount, timestamp (ms) and digest = base64(md5(bizContent + privateKey)).
 * Answers are {code, msg, data} with code "1" = success. Keys are never logged.
 */
class JtClient
{
    public const BASE = [
        CarrierAccount::PRODUCTION => 'https://ylopenapi.jtexpress.vn/webopenplatformapi/api/',
        CarrierAccount::SANDBOX => 'https://demoopenapi.jtexpress.vn/webopenplatformapi/api/',
    ];

    /** Codes J&T gives for a wrong key, password or account. */
    public const AUTH_CODES = ['145003010', '145003030', '145003031', '145003050', '145003051', '145003052'];

    public static function headerDigest(string $bizContent, string $privateKey): string
    {
        return base64_encode(md5($bizContent.$privateKey, true));
    }

    /** The "digest" inside bizContent of order calls: proves the customer code and its API password. */
    public static function businessDigest(string $customerCode, string $password, string $privateKey): string
    {
        return base64_encode(md5($customerCode.strtoupper(md5($password.'jadada236t2')).$privateKey, true));
    }

    /**
     * @param  array{api_account?: string, private_key?: string}  $credentials
     * @param  bool  $sideEffect  the call makes something at J&T: a lost answer is "uncertain"
     * @return array{code: string, msg: string, data: mixed}
     */
    public function call(array $credentials, string $environment, string $path, array $biz, bool $sideEffect = false, bool $allowRefusal = false): array
    {
        $json = json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $key = (string) ($credentials['private_key'] ?? '');
        try {
            $response = Http::asForm()->acceptJson()->timeout((int) config('shipping.timeout'))
                ->withHeaders([
                    'apiAccount' => (string) ($credentials['api_account'] ?? ''),
                    'digest' => self::headerDigest($json, $key),
                    'timestamp' => (string) (int) round(microtime(true) * 1000),
                ])
                ->post((self::BASE[$environment] ?? self::BASE[CarrierAccount::PRODUCTION]).$path, ['bizContent' => $json]);
        } catch (ConnectionException $e) {
            Log::warning('jt.unreachable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new CarrierException('J&T không trả lời kịp. Thử lại sau.', uncertain: $sideEffect);
        }
        if ($response->serverError()) {
            throw new CarrierException('J&T đang lỗi. Thử lại sau.', uncertain: $sideEffect);
        }
        $body = $response->json();
        if (! is_array($body) || ! array_key_exists('code', $body)) {
            throw new CarrierException('J&T trả lời không đúng định dạng.', uncertain: $sideEffect);
        }
        $code = (string) $body['code'];
        $msg = trim((string) ($body['msg'] ?? ''));
        if ($code !== '1') {
            Log::info('jt.refused', ['path' => $path, 'code' => $code, 'msg' => mb_substr($msg, 0, 200)]);
            $auth = in_array($code, self::AUTH_CODES, true) || preg_match('/digest|signature|chữ ký|apiAccount|password|mật khẩu/iu', $msg) === 1;
            if ($auth) {
                throw new CarrierException('J&T không nhận thông tin kết nối (mã khách hàng, API Account, Private Key hoặc mật khẩu API). J&T báo: '.mb_substr($msg !== '' ? $msg : $code, 0, 150), authFailed: true);
            }
            if (! $allowRefusal) {
                throw new CarrierException('J&T: '.mb_substr($msg !== '' ? $msg : "từ chối yêu cầu (mã {$code}).", 0, 220));
            }
        }

        return ['code' => $code, 'msg' => $msg, 'data' => $body['data'] ?? null];
    }
}
