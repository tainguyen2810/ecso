<?php

namespace App\Modules\Invoicing\Providers\Vnpt;

use App\Modules\Invoicing\Models\EInvoice;
use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Invoicing\Providers\EInvoiceException;
use App\Modules\Invoicing\Providers\EInvoiceProvider;
use App\Modules\Invoicing\Providers\EInvoiceResult;
use App\Modules\Invoicing\Providers\InvoiceLines;
use App\Modules\Invoicing\Providers\ProvidesPdf;
use App\Modules\Invoicing\Support\VndWords;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VNPT Invoice. Each shop has its own portal (0312345678-tt78admin.vnpt-invoice.com.vn) with SOAP services:
 * PublishService.asmx ImportAndPublishInv (service account + admin account, pattern, serial, the invoices as XML)
 * answers "OK:pattern;serial-fkey_number" or "ERR:n". Our reference code is the invoice's fkey, so an uncertain
 * request is looked up (PortalService listInvByCusFkey) before being sent again; the PDF comes from
 * downloadInvPDFFkeyNoPay (base64).
 */
class VnptProvider implements EInvoiceProvider, ProvidesPdf
{
    public const KEY = 'vnpt';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'VNPT Invoice';
    }

    public function credentialFields(): array
    {
        return [
            'portal' => 'Địa chỉ trang hóa đơn (vd. 0312345678-tt78admin.vnpt-invoice.com.vn)',
            'service_user' => 'Tài khoản dịch vụ (webservice)',
            'service_password' => 'Mật khẩu dịch vụ',
            'admin_user' => 'Tài khoản quản trị',
            'admin_password' => 'Mật khẩu quản trị',
            'pattern' => 'Mẫu số (vd. 1/001)',
            'serial' => 'Ký hiệu (vd. C25TAA)',
        ];
    }

    public function connect(array $credentials, string $environment): array
    {
        foreach ($this->credentialFields() as $field => $label) {
            if (blank($credentials[$field] ?? null)) {
                throw new EInvoiceException('Nhập đủ thông tin VNPT cấp: '.mb_strtolower(explode(' (', $label)[0]).'.');
            }
        }
        $portal = self::portal($credentials);
        if (! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.vnpt-invoice\.com\.vn$/', $portal)) {
            throw new EInvoiceException('Địa chỉ trang hóa đơn VNPT có dạng 0312345678-tt78admin.vnpt-invoice.com.vn.');
        }
        // There is no "who am I" call: asking for an invoice that cannot exist tells a wrong account (ERR:1) apart.
        $answer = $this->soap($credentials, 'PortalService', 'downloadInvPDFFkeyNoPay', ['fkey' => 'EZKIEMTRAKETNOI', 'userName' => $credentials['service_user'], 'userPass' => $credentials['service_password']]);
        if ($answer === 'ERR:1') {
            throw new EInvoiceException('VNPT không nhận tài khoản dịch vụ / mật khẩu này.', authFailed: true);
        }
        $pattern = strtoupper((string) $credentials['pattern']);
        $serial = strtoupper((string) $credentials['serial']);

        return ['account' => $portal, 'provider_name' => null, 'templates' => [
            ['template_code' => $pattern, 'invoice_series' => $serial, 'label' => "{$pattern} - {$serial}".(str_starts_with($pattern, '1') ? ' - Hóa đơn giá trị gia tăng' : ' - Hóa đơn bán hàng')],
        ]];
    }

    public function submit(EInvoiceAccount $account, array $invoice): string
    {
        $c = (array) $account->credentials;
        $answer = $this->soap($c, 'PublishService', 'ImportAndPublishInv', [
            'Account' => $c['admin_user'] ?? '',
            'ACpass' => $c['admin_password'] ?? '',
            'xmlInvData' => self::xml($invoice),
            'username' => $c['service_user'] ?? '',
            'password' => $c['service_password'] ?? '',
            'pattern' => $invoice['template_code'],
            'serial' => $invoice['invoice_series'],
            'convert' => '0',
        ], sideEffect: true);
        if (! str_starts_with($answer, 'OK:')) {
            throw new EInvoiceException(self::error($answer), authFailed: $answer === 'ERR:1');
        }
        // OK:1/001;C25TAA-EZ01..._123
        $number = preg_match('/_(\d+)\s*$/', $answer, $m) ? $m[1] : '';

        return 'VNPT:'.$number;
    }

    public function check(EInvoiceAccount $account, string $trackingCode): EInvoiceResult
    {
        $number = substr($trackingCode, 5);

        return str_starts_with($trackingCode, 'VNPT:') && $number !== ''
            ? new EInvoiceResult(EInvoiceResult::SUCCESS, null, $number, now()->toDateString(), invoiceStatus: 'issued')
            : new EInvoiceResult(EInvoiceResult::PENDING);
    }

    public function find(EInvoiceAccount $account, string $referenceCode): ?EInvoiceResult
    {
        $c = (array) $account->credentials;
        $answer = $this->soap($c, 'PortalService', 'listInvByCusFkey', [
            'key' => $referenceCode, 'fromDate' => '', 'toDate' => '', 'userName' => $c['service_user'] ?? '', 'userPass' => $c['service_password'] ?? '',
        ]);
        if ($answer === '' || str_starts_with($answer, 'ERR:')) {
            return null;
        }
        $number = preg_match('#<(?:invNum|InvNum|invNo|No)>\s*0*(\d+)\s*</#', html_entity_decode($answer), $m) ? $m[1] : '';

        return $number === '' ? null : new EInvoiceResult(EInvoiceResult::SUCCESS, null, $number, now()->toDateString(), invoiceStatus: 'issued');
    }

    public function pdf(EInvoiceAccount $account, EInvoice $invoice): string
    {
        $c = (array) $account->credentials;
        $answer = $this->soap($c, 'PortalService', 'downloadInvPDFFkeyNoPay', ['fkey' => $invoice->reference_code, 'userName' => $c['service_user'] ?? '', 'userPass' => $c['service_password'] ?? '']);
        $file = str_starts_with($answer, 'ERR:') ? false : base64_decode($answer, true);
        if (! $file) {
            throw new EInvoiceException('VNPT chưa trả file PDF của hóa đơn này.');
        }

        return $file;
    }

    /**
     * The invoice as VNPT's import XML (Invoices / Inv / key + Invoice with Products).
     *
     * @param  array<string, mixed>  $invoice
     */
    public static function xml(array $invoice): string
    {
        $sum = InvoiceLines::compute($invoice);
        $vat = str_starts_with((string) $invoice['template_code'], '1');
        $buyer = (array) ($invoice['buyer'] ?? []);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $tag = fn (string $name, $value) => $value === null || $value === '' ? '' : "<{$name}>".$e($value)."</{$name}>";

        $products = '';
        foreach ($sum['lines'] as $l) {
            $products .= '<Product>'.$tag('Code', $l['item_code'] ?? null).$tag('ProdName', $l['item_name']).$tag('ProdUnit', $l['unit'] ?? null)
                .$tag('ProdQuantity', $l['quantity']).$tag('ProdPrice', $l['unit_price']).$tag('Discount', $l['discount'] ?: null)
                .$tag('Total', $l['net']).($vat ? $tag('VATRate', $l['rate'] === -2 ? -1 : $l['rate']).$tag('VATAmount', $l['vat']) : '').$tag('Amount', $l['total']).'</Product>';
        }
        $groups = '';
        if ($vat) {
            foreach ($sum['rates'] as $r) {
                $groups .= match ($r['rate']) {
                    0, 5, 10 => $tag('GrossValue'.$r['rate'], $r['net']).$tag('VatAmount'.$r['rate'], $r['vat']),
                    -2 => $tag('GrossValue_NonTax', $r['net']),
                    default => '',
                };
            }
        }
        $company = filled($buyer['tax_code'] ?? null);

        return '<Invoices><Inv>'.$tag('key', $invoice['reference_code']).'<Invoice>'
            .$tag('CusCode', $buyer['buyer_code'] ?? null)
            .$tag('Buyer', $company ? null : ($buyer['name'] ?? null))
            .$tag('CusName', $company ? ($buyer['legal_name'] ?? $buyer['name'] ?? null) : ($buyer['name'] ?? null))
            .$tag('CusAddress', $buyer['address'] ?? null).$tag('CusPhone', $buyer['phone'] ?? null)
            .$tag('CusTaxCode', $buyer['tax_code'] ?? null).$tag('EmailDeliver', $buyer['email'] ?? null)
            .$tag('PaymentMethod', 'TM/CK').$tag('ArisingDate', now()->format('d/m/Y')).$tag('CurrencyUnit', 'VND')
            .'<Products>'.$products.'</Products>'
            .$tag('Total', $sum['net']).$tag('DiscountAmount', $sum['discount'] ?: null).$tag('VATAmount', $vat ? $sum['vat'] : null).$groups
            .$tag('Amount', $sum['total']).$tag('AmountInWords', VndWords::spell($sum['total']))
            .$tag('Extra', $invoice['notes'] ?? null)
            .'</Invoice></Inv></Invoices>';
    }

    private static function portal(array $credentials): string
    {
        return strtolower(trim(preg_replace('#^https?://|/.*$#i', '', trim((string) ($credentials['portal'] ?? ''))) ?? ''));
    }

    private static function error(string $answer): string
    {
        return match ($answer) {
            'ERR:1' => 'VNPT không nhận tài khoản dịch vụ hoặc tài khoản quản trị.',
            'ERR:20' => 'VNPT báo mẫu số / ký hiệu không đúng hoặc chưa được phép dùng.',
            '' => 'VNPT không trả kết quả. Thử lại sau.',
            default => 'VNPT từ chối hóa đơn ('.mb_substr($answer, 0, 80).').',
        };
    }

    /** Calls one SOAP 1.1 method on the shop's portal and returns its Result text. */
    private function soap(array $credentials, string $service, string $method, array $params, bool $sideEffect = false): string
    {
        $body = '';
        foreach ($params as $name => $value) {
            $body .= "<{$name}>".htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</{$name}>";
        }
        $envelope = '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            ."<soap:Body><{$method} xmlns=\"http://tempuri.org/\">{$body}</{$method}></soap:Body></soap:Envelope>";
        try {
            $response = Http::withHeaders(['SOAPAction' => "\"http://tempuri.org/{$method}\""])
                ->withBody($envelope, 'text/xml; charset=utf-8')
                ->timeout((int) config('einvoice.timeout'))
                ->post('https://'.self::portal($credentials)."/{$service}.asmx");
        } catch (ConnectionException $e) {
            Log::warning('vnpt.einvoice.unreachable', ['method' => $method, 'error' => $e->getMessage()]);
            throw new EInvoiceException($sideEffect
                ? 'VNPT không trả lời kịp. Hóa đơn có thể đã được tạo: bấm "Kiểm tra lại" trước khi xuất lại.'
                : 'Không gọi được trang hóa đơn VNPT. Kiểm tra lại địa chỉ trang.', uncertain: $sideEffect);
        }
        if ($response->status() === 404) {
            throw new EInvoiceException('Địa chỉ trang hóa đơn VNPT không đúng (không có dịch vụ '.$service.').');
        }
        if (! $response->successful()) {
            throw new EInvoiceException('VNPT Invoice đang lỗi (mã '.$response->status().'). Thử lại sau.', uncertain: $sideEffect && $response->serverError());
        }
        if (! preg_match("#<{$method}Result>(.*?)</{$method}Result>#s", $response->body(), $m)) {
            return preg_match("#<{$method}Result\s*/>#", $response->body()) ? '' : throw new EInvoiceException('VNPT trả kết quả không đọc được.', uncertain: $sideEffect);
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
}
