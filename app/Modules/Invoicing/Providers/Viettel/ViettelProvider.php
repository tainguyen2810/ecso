<?php

namespace App\Modules\Invoicing\Providers\Viettel;

use App\Modules\Invoicing\Models\EInvoice;
use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Invoicing\Providers\EInvoiceException;
use App\Modules\Invoicing\Providers\EInvoiceProvider;
use App\Modules\Invoicing\Providers\EInvoiceResult;
use App\Modules\Invoicing\Providers\InvoiceLines;
use App\Modules\Invoicing\Providers\ProvidesPdf;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Viettel S-Invoice (sinvoice.viettel.vn). The shop asks Viettel for an API account (user + password, not the web
 * login) and types its template ("1/001") and series ("C25TMP"): Viettel has no call that lists them.
 *
 * Login: POST /auth/login {username, password} → access_token, sent as the cookie access_token. Invoices are issued
 * synchronously: createInvoice/{tax code} answers with the invoice number. Our reference code is the invoice's
 * transactionUuid, so an uncertain request is looked up with searchInvoiceByTransactionUuid instead of sent again.
 * The PDF comes from getInvoiceRepresentationFile (base64), served through our own link.
 */
class ViettelProvider implements EInvoiceProvider, ProvidesPdf
{
    public const KEY = 'viettel';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Viettel S-Invoice';
    }

    public function credentialFields(): array
    {
        return [
            'tax_code' => 'Mã số thuế của shop',
            'username' => 'Tên đăng nhập API',
            'password' => 'Mật khẩu API',
            'template_code' => 'Mẫu số (vd. 1/001)',
            'invoice_series' => 'Ký hiệu (vd. C25TMP)',
        ];
    }

    public function connect(array $credentials, string $environment): array
    {
        foreach (['tax_code' => 'mã số thuế', 'username' => 'tên đăng nhập API', 'password' => 'mật khẩu API', 'template_code' => 'mẫu số', 'invoice_series' => 'ký hiệu'] as $field => $name) {
            if (blank($credentials[$field] ?? null)) {
                throw new EInvoiceException("Nhập {$name} Viettel cấp cho shop.");
            }
        }
        if (! preg_match('/^\d{10}(-\d{3})?$/', (string) $credentials['tax_code'])) {
            throw new EInvoiceException('Mã số thuế gồm 10 số (chi nhánh: 10 số-3 số).');
        }
        $this->token($credentials, true);
        $code = strtoupper((string) $credentials['template_code']);
        $series = strtoupper((string) $credentials['invoice_series']);

        return ['account' => (string) $credentials['tax_code'], 'provider_name' => null, 'templates' => [
            ['template_code' => $code, 'invoice_series' => $series, 'label' => "{$code} - {$series}".(str_starts_with($code, '1') ? ' - Hóa đơn giá trị gia tăng' : ' - Hóa đơn bán hàng')],
        ]];
    }

    public function submit(EInvoiceAccount $account, array $invoice): string
    {
        $creds = (array) $account->credentials;
        $json = $this->call($creds, 'POST', '/InvoiceAPI/InvoiceWS/createInvoice/'.rawurlencode((string) $creds['tax_code']), self::payload($invoice), sideEffect: true);
        $number = (string) ($json['result']['invoiceNo'] ?? '');
        if ($number === '') {
            throw new EInvoiceException('Viettel chưa trả số hóa đơn. Bấm "Kiểm tra lại" để kiểm tra.', uncertain: true);
        }

        return 'VT:'.$number;
    }

    public function check(EInvoiceAccount $account, string $trackingCode): EInvoiceResult
    {
        if (str_starts_with($trackingCode, 'VT:') && strlen($trackingCode) > 3) {
            return new EInvoiceResult(EInvoiceResult::SUCCESS, null, substr($trackingCode, 3), now()->toDateString(), invoiceStatus: 'issued');
        }

        return new EInvoiceResult(EInvoiceResult::PENDING);
    }

    public function find(EInvoiceAccount $account, string $referenceCode): ?EInvoiceResult
    {
        $creds = (array) $account->credentials;
        $json = $this->call($creds, 'FORM', '/InvoiceAPI/InvoiceWS/searchInvoiceByTransactionUuid', ['supplierTaxCode' => $creds['tax_code'] ?? '', 'transactionUuid' => $referenceCode], allowMissing: true);
        $result = $json['result'] ?? null;
        $row = is_array($result) && array_is_list($result) ? ($result[0] ?? null) : $result;
        $number = is_array($row) ? (string) ($row['invoiceNo'] ?? '') : '';

        return $number === '' ? null : new EInvoiceResult(EInvoiceResult::SUCCESS, null, $number, now()->toDateString(), invoiceStatus: 'issued');
    }

    public function pdf(EInvoiceAccount $account, EInvoice $invoice): string
    {
        $creds = (array) $account->credentials;
        $json = $this->call($creds, 'POST', '/InvoiceAPI/InvoiceUtilsWS/getInvoiceRepresentationFile', [
            'supplierTaxCode' => $creds['tax_code'] ?? '',
            'invoiceNo' => $invoice->invoice_number,
            'templateCode' => $invoice->template_code,
            'transactionUuid' => $invoice->reference_code,
            'fileType' => 'PDF',
        ]);
        $file = base64_decode((string) ($json['fileToBytes'] ?? ''), true);
        if (! $file) {
            throw new EInvoiceException('Viettel chưa trả file PDF của hóa đơn này.');
        }

        return $file;
    }

    /**
     * Viettel's createInvoice body from the neutral invoice.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    public static function payload(array $invoice): array
    {
        $sum = InvoiceLines::compute($invoice);
        $vat = str_starts_with((string) $invoice['template_code'], '1');
        $buyer = (array) ($invoice['buyer'] ?? []);
        $noInvoice = ($buyer['name'] ?? '') === 'Người mua không lấy hóa đơn';

        return [
            'generalInvoiceInfo' => [
                'invoiceType' => $vat ? '1' : '2',
                'templateCode' => $invoice['template_code'],
                'invoiceSeries' => $invoice['invoice_series'],
                'currencyCode' => 'VND',
                'adjustmentType' => '1',
                'paymentStatus' => true,
                'cusGetInvoiceRight' => true,
                'transactionUuid' => $invoice['reference_code'],
            ],
            'buyerInfo' => array_filter([
                'buyerName' => $buyer['name'] ?? null,
                'buyerLegalName' => $buyer['legal_name'] ?? null,
                'buyerTaxCode' => $buyer['tax_code'] ?? null,
                'buyerAddressLine' => $buyer['address'] ?? null,
                'buyerEmail' => $buyer['email'] ?? null,
                'buyerPhoneNumber' => $buyer['phone'] ?? null,
                'buyerCode' => $buyer['buyer_code'] ?? null,
                'buyerNotGetInvoice' => $noInvoice ? 1 : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'payments' => [['paymentMethodName' => 'TM/CK']],
            'itemInfo' => array_map(fn ($l) => array_filter([
                'lineNumber' => $l['line_number'],
                'selection' => 1,
                'itemCode' => $l['item_code'] ?? null,
                'itemName' => $l['item_name'],
                'unitName' => $l['unit'] ?? null,
                'quantity' => $l['quantity'],
                'unitPrice' => $l['unit_price'],
                'itemDiscount' => $l['discount'] ?: null,
                'itemTotalAmountWithoutTax' => $l['net'],
                'taxPercentage' => $vat ? $l['rate'] : null,
                'taxAmount' => $vat ? $l['vat'] : null,
                'itemTotalAmountWithTax' => $l['total'],
            ], fn ($v) => $v !== null), $sum['lines']),
            'taxBreakdowns' => $vat ? array_values(array_map(fn ($r) => ['taxPercentage' => $r['rate'], 'taxableAmount' => $r['net'], 'taxAmount' => $r['vat']], $sum['rates'])) : [],
            'summarizeInfo' => [
                'totalAmountWithoutTax' => $sum['net'],
                'totalTaxAmount' => $sum['vat'],
                'totalAmountWithTax' => $sum['total'],
                'discountAmount' => $sum['discount'],
            ],
        ];
    }

    /** @param  array<string, mixed>  $credentials */
    private function token(array $credentials, bool $fresh = false): string
    {
        $key = 'einvoice:viettel:token:'.hash('sha256', ($credentials['username'] ?? '').'|'.($credentials['password'] ?? ''));
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addHours(12), function () use ($credentials) {
            $body = ['username' => (string) ($credentials['username'] ?? ''), 'password' => (string) ($credentials['password'] ?? '')];
            $response = null;
            foreach (self::loginUrls() as $url) {
                try {
                    $response = Http::acceptJson()->timeout((int) config('einvoice.timeout'))->post($url, $body);
                } catch (ConnectionException $e) {
                    Log::warning('viettel.einvoice.unreachable', ['path' => 'auth/login', 'error' => $e->getMessage()]);
                    throw new EInvoiceException('Viettel S-Invoice không trả lời kịp. Thử lại sau.');
                }
                if ($response->status() !== 404) {
                    break;
                }
            }
            $token = $response?->json('access_token');
            if (in_array($response?->status(), [400, 401, 403], true) || (! is_string($token) && $response?->successful())) {
                throw new EInvoiceException('Viettel không nhận tên đăng nhập / mật khẩu API này. Đây là tài khoản API Viettel cấp riêng, khác tài khoản vào trang hóa đơn.', authFailed: true);
            }
            if (! is_string($token) || $token === '') {
                throw new EInvoiceException('Viettel S-Invoice đang lỗi khi đăng nhập. Thử lại sau.');
            }

            return $token;
        });
    }

    /** @return array<int, string> */
    private static function loginUrls(): array
    {
        $base = rtrim((string) config('einvoice.viettel.base'), '/');
        $root = preg_replace('#^(https?://[^/]+).*$#', '$1', $base);

        return array_values(array_unique([$root.'/auth/login', $base.'/auth/login']));
    }

    /**
     * @param  'POST'|'FORM'  $method  FORM: form-encoded POST
     * @return array<string, mixed>|null
     */
    private function call(array $credentials, string $method, string $path, array $body, bool $sideEffect = false, bool $allowMissing = false): ?array
    {
        $send = function (bool $fresh) use ($credentials, $method, $path, $body, $sideEffect): Response {
            $request = Http::withHeaders(['Cookie' => 'access_token='.$this->token($credentials, $fresh)])->acceptJson()->timeout((int) config('einvoice.timeout'));
            $url = rtrim((string) config('einvoice.viettel.base'), '/').$path;
            try {
                return $method === 'FORM' ? $request->asForm()->post($url, $body) : $request->post($url, $body);
            } catch (ConnectionException $e) {
                Log::warning('viettel.einvoice.unreachable', ['path' => $path, 'error' => $e->getMessage()]);
                throw new EInvoiceException($sideEffect
                    ? 'Viettel không trả lời kịp. Hóa đơn có thể đã được tạo: bấm "Kiểm tra lại" trước khi xuất lại.'
                    : 'Viettel S-Invoice không trả lời kịp. Thử lại sau.', uncertain: $sideEffect);
            }
        };
        $response = $send(false);
        if ($response->status() === 401) {
            $response = $send(true);
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new EInvoiceException('Viettel không nhận phiên đăng nhập. Kiểm tra lại tài khoản API.', authFailed: true);
        }
        if ($response->status() === 404 && $allowMissing) {
            return null;
        }
        $json = (array) $response->json();
        if ($response->serverError() && empty($json['errorCode'])) {
            throw new EInvoiceException('Viettel S-Invoice đang lỗi. Thử lại sau.', uncertain: $sideEffect);
        }
        $error = $json['errorCode'] ?? null;
        if ($error !== null && $error !== '' && ! ($allowMissing && in_array(strtoupper((string) $error), ['NOT_FOUND', 'INVOICE_NOT_FOUND', 'TRANSACTION_NOT_FOUND'], true))) {
            $message = trim((string) ($json['description'] ?? 'Viettel từ chối hóa đơn.'));
            Log::info('viettel.einvoice.refused', ['path' => $path, 'status' => $response->status(), 'code' => $error]);
            throw new EInvoiceException('Viettel: '.mb_substr($message, 0, 220)." ({$error})", uncertain: $sideEffect && strtoupper((string) $error) === 'GENERAL');
        }
        if (! $response->successful()) {
            throw new EInvoiceException('Viettel từ chối yêu cầu (mã '.$response->status().').');
        }

        return $json;
    }
}
