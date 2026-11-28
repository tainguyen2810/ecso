<?php

namespace App\Modules\Invoicing\Providers\Misa;

use App\Modules\Invoicing\Models\EInvoice;
use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Invoicing\Providers\EInvoiceException;
use App\Modules\Invoicing\Providers\EInvoiceProvider;
use App\Modules\Invoicing\Providers\EInvoiceResult;
use App\Modules\Invoicing\Providers\InvoiceLines;
use App\Modules\Invoicing\Providers\ProvidesPdf;
use App\Modules\Invoicing\Support\VndWords;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MISA meInvoice (integration API v3). Calls carry our software's AppID (config einvoice.misa.app_id, given by MISA
 * to partners) and the shop's own meInvoice login. The invoice is created as a draft with our reference code as RefID
 * and signed by the shop in meInvoice (USB token or remote signing), so it stays "pending" until MISA reports its
 * number (invoice-status/refid). The PDF comes from downloadinvoice (base64), served through our own link.
 */
class MisaProvider implements EInvoiceProvider, ProvidesPdf
{
    public const KEY = 'misa';

    public const WAITING = 'Đã tạo hóa đơn nháp trên MISA meInvoice. Vào meinvoice.vn ký phát hành, rồi bấm "Kiểm tra lại".';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'MISA meInvoice';
    }

    public static function configured(): bool
    {
        return filled(config('einvoice.misa.app_id'));
    }

    public function credentialFields(): array
    {
        return ['tax_code' => 'Mã số thuế của shop', 'username' => 'Tài khoản meInvoice', 'password' => 'Mật khẩu meInvoice'];
    }

    public function connect(array $credentials, string $environment): array
    {
        if (blank($credentials['tax_code'] ?? null) || blank($credentials['username'] ?? null) || blank($credentials['password'] ?? null)) {
            throw new EInvoiceException('Nhập mã số thuế, tài khoản và mật khẩu meInvoice.');
        }
        $who = ['credentials' => $credentials, 'environment' => $environment];
        $this->token($credentials, $environment, true);
        $rows = self::rows($this->call($who, 'GET', 'code/itg/InvoicePublishing/templates', ['invyear' => now()->year]));
        $templates = collect($rows)
            ->filter(fn ($t) => filled($t['InvSeries'] ?? null) && ! ($t['Inactive'] ?? false))
            ->map(function ($t) {
                $series = (string) $t['InvSeries'];
                $code = (string) ($t['InvTemplateNo'] ?? substr($series, 0, 1));

                return ['template_code' => $code, 'invoice_series' => $series, 'label' => "{$code} - {$series}".(str_starts_with($code, '1') ? ' - Hóa đơn giá trị gia tăng' : ' - Hóa đơn bán hàng')];
            })->unique(fn ($t) => $t['template_code'].'|'.$t['invoice_series'])->values()->all();
        if ($templates === []) {
            throw new EInvoiceException('Tài khoản meInvoice chưa có mẫu / ký hiệu hóa đơn nào đang dùng trong năm nay.');
        }

        return ['account' => (string) $credentials['tax_code'], 'provider_name' => null, 'templates' => $templates];
    }

    public function submit(EInvoiceAccount $account, array $invoice): string
    {
        $rows = self::rows($this->call($account, 'POST', 'code/itg/invoicepublishing/createinvoice', [self::payload($invoice)], sideEffect: true));
        $row = collect($rows)->first(fn ($r) => ($r['RefID'] ?? null) === $invoice['reference_code']) ?? collect($rows)->first() ?? [];
        $code = (string) ($row['ErrorCode'] ?? '');
        if ($code !== '' && ! in_array($code, ['DuplicateRefID', 'InvoiceIsPublished'], true)) {
            throw new EInvoiceException('MISA: '.self::message($row));
        }

        return 'MISA:'.$invoice['reference_code'];
    }

    public function check(EInvoiceAccount $account, string $trackingCode): EInvoiceResult
    {
        return $this->find($account, str_starts_with($trackingCode, 'MISA:') ? substr($trackingCode, 5) : $trackingCode)
            ?? new EInvoiceResult(EInvoiceResult::PENDING, self::WAITING);
    }

    public function find(EInvoiceAccount $account, string $referenceCode): ?EInvoiceResult
    {
        $rows = self::rows($this->call($account, 'POST', 'code/itg/invoicepublished/invoice-status/refid', [$referenceCode]));
        $row = collect($rows)->first(fn ($r) => ($r['RefID'] ?? null) === $referenceCode);
        if ($row === null) {
            return null;
        }
        $number = ltrim((string) ($row['InvNo'] ?? ''), '0');
        if ($number === '') {
            return new EInvoiceResult(EInvoiceResult::PENDING, self::WAITING, invoiceStatus: 'draft');
        }
        if (in_array((int) ($row['EInvoiceStatus'] ?? 0), [2, 3], true)) {
            return new EInvoiceResult(EInvoiceResult::FAILED, 'Hóa đơn đã bị hủy hoặc thay thế trên MISA.');
        }

        return new EInvoiceResult(EInvoiceResult::SUCCESS, null, (string) $row['InvNo'], isset($row['InvDate']) ? substr((string) $row['InvDate'], 0, 10) : now()->toDateString(), invoiceStatus: 'issued');
    }

    public function pdf(EInvoiceAccount $account, EInvoice $invoice): string
    {
        // The download needs MISA's own id of the invoice (TransactionID), found by our reference code.
        $row = collect(self::rows($this->call($account, 'POST', 'code/itg/invoicepublished/invoice-status/refid', [$invoice->reference_code])))->first();
        $transaction = (string) ($row['TransactionID'] ?? '');
        $rows = self::rows($this->call($account, 'POST', 'code/itg/invoicepublished/downloadinvoice?downloadDataType=pdf', [$transaction]));
        $file = base64_decode((string) ($rows[0]['Data'] ?? ''), true);
        if (! $file) {
            throw new EInvoiceException('MISA chưa trả file PDF của hóa đơn này.');
        }

        return $file;
    }

    /**
     * MISA's invoice from the neutral invoice.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    public static function payload(array $invoice): array
    {
        $sum = InvoiceLines::compute($invoice);
        $vat = str_starts_with((string) $invoice['template_code'], '1');
        $buyer = (array) ($invoice['buyer'] ?? []);
        $company = filled($buyer['tax_code'] ?? null);

        return array_filter([
            'RefID' => $invoice['reference_code'],
            'InvSeries' => $invoice['invoice_series'],
            'InvTemplateNo' => $invoice['template_code'],
            'InvDate' => now()->format('Y-m-d'),
            'CurrencyCode' => 'VND',
            'ExchangeRate' => 1,
            'PaymentMethodName' => 'TM/CK',
            'BuyerLegalName' => $company ? ($buyer['legal_name'] ?? $buyer['name'] ?? null) : null,
            'BuyerTaxCode' => $buyer['tax_code'] ?? null,
            'BuyerAddress' => $buyer['address'] ?? null,
            'BuyerFullName' => $company ? null : ($buyer['name'] ?? null),
            'BuyerCode' => $buyer['buyer_code'] ?? null,
            'BuyerEmail' => $buyer['email'] ?? null,
            'BuyerPhoneNumber' => $buyer['phone'] ?? null,
            'TotalSaleAmountOC' => $sum['net'] + $sum['discount'], 'TotalSaleAmount' => $sum['net'] + $sum['discount'],
            'TotalDiscountAmountOC' => $sum['discount'], 'TotalDiscountAmount' => $sum['discount'],
            'TotalAmountWithoutVATOC' => $sum['net'], 'TotalAmountWithoutVAT' => $sum['net'],
            'TotalVATAmountOC' => $sum['vat'], 'TotalVATAmount' => $sum['vat'],
            'TotalAmountOC' => $sum['total'], 'TotalAmount' => $sum['total'],
            'TotalAmountInWords' => VndWords::spell($sum['total']),
            'OriginalInvoiceDetail' => array_map(fn ($l) => array_filter([
                'ItemType' => 1,
                'LineNumber' => $l['line_number'],
                'SortOrder' => $l['line_number'],
                'ItemCode' => $l['item_code'] ?? null,
                'ItemName' => $l['item_name'],
                'UnitName' => $l['unit'] ?? null,
                'Quantity' => $l['quantity'],
                'UnitPrice' => $l['unit_price'],
                'AmountOC' => $l['gross'], 'Amount' => $l['gross'],
                'DiscountAmountOC' => $l['discount'], 'DiscountAmount' => $l['discount'],
                'AmountWithoutVATOC' => $l['net'], 'AmountWithoutVAT' => $l['net'],
                'VATRateName' => $vat ? InvoiceLines::rateName($l['rate']) : null,
                'VATAmountOC' => $vat ? $l['vat'] : null, 'VATAmount' => $vat ? $l['vat'] : null,
            ], fn ($v) => $v !== null), $sum['lines']),
            'TaxRateInfo' => $vat ? array_values(array_map(fn ($r) => ['VATRateName' => InvoiceLines::rateName($r['rate']), 'AmountWithoutVATOC' => $r['net'], 'VATAmountOC' => $r['vat']], $sum['rates'])) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * The rows of an answer: MISA puts them in Data, sometimes as a JSON string.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows(array $json): array
    {
        $data = $json['Data'] ?? $json['data'] ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            return [];
        }

        return array_is_list($data) ? array_values(array_filter($data, 'is_array')) : [$data];
    }

    private static function message(array $row): string
    {
        $code = (string) ($row['ErrorCode'] ?? '');

        return match ($code) {
            'InvalidTaxCode' => 'Mã số thuế người mua không đúng định dạng.',
            'InvalidInvSeries', 'InvSeriesNotFound' => 'Ký hiệu hóa đơn không còn dùng được trên meInvoice.',
            default => mb_substr(trim((string) ($row['DescriptionErrorCode'] ?? $row['ErrorMessage'] ?? $code ?: 'từ chối hóa đơn')), 0, 200).($code !== '' ? " ({$code})" : ''),
        };
    }

    private static function base(string $environment): string
    {
        return rtrim((string) config($environment === EInvoiceAccount::SANDBOX ? 'einvoice.misa.sandbox' : 'einvoice.misa.base'), '/').'/';
    }

    private function token(array $credentials, string $environment, bool $fresh = false): string
    {
        $key = 'einvoice:misa:token:'.hash('sha256', $environment.'|'.($credentials['tax_code'] ?? '').'|'.($credentials['username'] ?? '').'|'.($credentials['password'] ?? ''));
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addDays(7), function () use ($credentials, $environment) {
            if (! self::configured()) {
                throw new EInvoiceException('Chưa có mã ứng dụng MISA (AppID). Liên hệ EzChat Bán hàng.');
            }
            try {
                $response = Http::acceptJson()->timeout((int) config('einvoice.timeout'))->post(self::base($environment).'auth/token', [
                    'appid' => config('einvoice.misa.app_id'),
                    'taxcode' => $credentials['tax_code'] ?? '',
                    'username' => $credentials['username'] ?? '',
                    'password' => $credentials['password'] ?? '',
                ]);
            } catch (ConnectionException $e) {
                Log::warning('misa.einvoice.unreachable', ['path' => 'auth/token', 'error' => $e->getMessage()]);
                throw new EInvoiceException('MISA meInvoice không trả lời kịp. Thử lại sau.');
            }
            $json = (array) $response->json();
            $token = $json['Data'] ?? $json['data'] ?? $json['access_token'] ?? null;
            $token = is_array($token) ? ($token['access_token'] ?? $token['Token'] ?? null) : $token;
            if (! is_string($token) || $token === '' || ($json['Success'] ?? true) === false) {
                throw new EInvoiceException('MISA không nhận mã số thuế / tài khoản / mật khẩu meInvoice này.', authFailed: true);
            }

            return $token;
        });
    }

    /** @return array<string, mixed> */
    private function call(EInvoiceAccount|array $who, string $method, string $path, array $body = [], bool $sideEffect = false): array
    {
        [$credentials, $environment] = $who instanceof EInvoiceAccount ? [(array) $who->credentials, $who->environment] : [$who['credentials'], $who['environment']];
        $send = function (bool $fresh) use ($credentials, $environment, $method, $path, $body, $sideEffect) {
            $request = Http::withToken($this->token($credentials, $environment, $fresh))
                ->withHeaders(['CompanyTaxCode' => (string) ($credentials['tax_code'] ?? '')])
                ->acceptJson()->timeout((int) config('einvoice.timeout'));
            try {
                return $method === 'GET' ? $request->get(self::base($environment).$path, $body) : $request->post(self::base($environment).$path, $body);
            } catch (ConnectionException $e) {
                Log::warning('misa.einvoice.unreachable', ['path' => $path, 'error' => $e->getMessage()]);
                throw new EInvoiceException($sideEffect
                    ? 'MISA không trả lời kịp. Hóa đơn có thể đã được tạo: bấm "Kiểm tra lại" trước khi xuất lại.'
                    : 'MISA meInvoice không trả lời kịp. Thử lại sau.', uncertain: $sideEffect);
            }
        };
        $response = $send(false);
        if ($response->status() === 401) {
            $response = $send(true);
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new EInvoiceException('MISA không nhận phiên đăng nhập. Kiểm tra lại tài khoản meInvoice.', authFailed: true);
        }
        $json = (array) $response->json();
        if ($response->serverError()) {
            throw new EInvoiceException('MISA meInvoice đang lỗi. Thử lại sau.', uncertain: $sideEffect);
        }
        if (! $response->successful() || ($json['Success'] ?? true) === false) {
            Log::info('misa.einvoice.refused', ['path' => $path, 'status' => $response->status(), 'code' => $json['ErrorCode'] ?? null]);
            throw new EInvoiceException('MISA: '.self::message($json));
        }

        return $json;
    }
}
