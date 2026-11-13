<?php

namespace App\Modules\Invoicing\Providers\SePay;

use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Invoicing\Providers\EInvoiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SePay eInvoice API (developer.sepay.vn/en/einvoice-api/v1): a Bearer token from POST /v1/token with Basic
 * client_id:client_secret, valid 24 hours (kept 23 hours here). Answers are {success, data} or
 * {success: false, error: {code, message}}. Secrets and tokens are never logged.
 */
class SePayClient
{
    public const BASE = [
        EInvoiceAccount::PRODUCTION => 'https://einvoice-api.sepay.vn',
        EInvoiceAccount::SANDBOX => 'https://einvoice-api-sandbox.sepay.vn',
    ];

    /** @param  array{client_id?: string, client_secret?: string}  $credentials */
    public function token(array $credentials, string $environment, bool $fresh = false): string
    {
        $id = (string) ($credentials['client_id'] ?? '');
        $secret = (string) ($credentials['client_secret'] ?? '');
        if ($id === '' || $secret === '') {
            throw new EInvoiceException('Nhập Client ID và Client Secret của SePay eInvoice.', authFailed: true);
        }
        $key = 'einvoice:sepay:token:'.hash('sha256', $environment.'|'.$id.'|'.$secret);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addHours(23), function () use ($id, $secret, $environment) {
            try {
                $response = Http::withBasicAuth($id, $secret)->acceptJson()->timeout((int) config('einvoice.timeout'))
                    ->post(self::base($environment).'/v1/token');
            } catch (ConnectionException $e) {
                Log::warning('sepay.einvoice.unreachable', ['path' => 'token', 'error' => $e->getMessage()]);
                throw new EInvoiceException('SePay eInvoice không trả lời kịp. Thử lại sau.');
            }
            if (in_array($response->status(), [400, 401, 403], true)) {
                throw new EInvoiceException('SePay không nhận Client ID / Client Secret này. Kiểm tra lại ở my.sepay.vn.', authFailed: true);
            }
            $token = $response->json('data.access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new EInvoiceException('SePay eInvoice đang lỗi khi cấp phiên làm việc. Thử lại sau.');
            }

            return $token;
        });
    }

    /**
     * @param  bool  $sideEffect  the call issues an invoice: a lost answer is "uncertain", not a plain failure
     * @return array<string, mixed>|null the answer's data, null on 404 when $allowMissing
     */
    public function call(EInvoiceAccount|array $who, string $method, string $path, array $body = [], bool $sideEffect = false, bool $allowMissing = false): ?array
    {
        [$credentials, $environment] = $who instanceof EInvoiceAccount ? [(array) $who->credentials, $who->environment] : [$who['credentials'], $who['environment']];
        $response = $this->send($credentials, $environment, $method, $path, $body, $sideEffect, false);
        if ($response->status() === 401) {
            $response = $this->send($credentials, $environment, $method, $path, $body, $sideEffect, true);
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new EInvoiceException('SePay không nhận phiên làm việc. Kiểm tra lại Client ID / Client Secret.', authFailed: true);
        }
        if ($response->status() === 404 && $allowMissing) {
            return null;
        }
        $json = (array) $response->json();
        if ($response->serverError() && ! isset($json['error']['code'])) {
            throw new EInvoiceException('SePay eInvoice đang lỗi. Thử lại sau.', uncertain: $sideEffect);
        }
        if (! $response->successful() || ($json['success'] ?? false) !== true) {
            $code = (string) ($json['error']['code'] ?? $response->status());
            $message = trim((string) ($json['error']['message'] ?? $json['message'] ?? 'SePay từ chối yêu cầu.'));
            Log::info('sepay.einvoice.refused', ['path' => $path, 'status' => $response->status(), 'code' => $code]);
            throw new EInvoiceException('SePay: '.mb_substr($message, 0, 220).($code !== '' ? " ({$code})" : ''));
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    public static function base(string $environment): string
    {
        return self::BASE[$environment] ?? self::BASE[EInvoiceAccount::PRODUCTION];
    }

    private function send(array $credentials, string $environment, string $method, string $path, array $body, bool $sideEffect, bool $freshToken): Response
    {
        $token = $this->token($credentials, $environment, $freshToken);
        try {
            $request = Http::withToken($token)->acceptJson()->timeout((int) config('einvoice.timeout'));

            return $method === 'GET' ? $request->get(self::base($environment).$path, $body) : $request->post(self::base($environment).$path, $body);
        } catch (ConnectionException $e) {
            Log::warning('sepay.einvoice.unreachable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new EInvoiceException($sideEffect
                ? 'SePay không trả lời kịp. Hóa đơn có thể đã được tạo: bấm "Kiểm tra lại" để kiểm tra trước khi xuất lại.'
                : 'SePay eInvoice không trả lời kịp. Thử lại sau.', uncertain: $sideEffect);
        }
    }
}
