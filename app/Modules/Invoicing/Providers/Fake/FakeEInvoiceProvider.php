<?php

namespace App\Modules\Invoicing\Providers\Fake;

use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Invoicing\Providers\EInvoiceException;
use App\Modules\Invoicing\Providers\EInvoiceProvider;
use App\Modules\Invoicing\Providers\EInvoiceResult;
use Illuminate\Support\Facades\Cache;

/**
 * "Nhà cung cấp giả lập": issues invoices locally (numbers 00000001...), for demos and tests. Credentials with
 * client_secret "wrong" are refused; a buyer named "LỖI" makes the invoice fail. Off in production.
 */
class FakeEInvoiceProvider implements EInvoiceProvider
{
    public const KEY = 'fake';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Nhà cung cấp giả lập';
    }

    public function credentialFields(): array
    {
        return ['client_id' => 'Client ID', 'client_secret' => 'Client Secret'];
    }

    public function connect(array $credentials, string $environment): array
    {
        if (($credentials['client_secret'] ?? '') === 'wrong' || blank($credentials['client_id'] ?? null)) {
            throw new EInvoiceException('Nhà cung cấp giả lập từ chối thông tin đăng nhập.', authFailed: true);
        }

        return ['account' => 'fake-account', 'provider_name' => 'giả lập', 'templates' => [
            ['template_code' => '1', 'invoice_series' => 'C26TEZ', 'label' => '1 - C26TEZ - Hóa đơn giá trị gia tăng'],
            ['template_code' => '2', 'invoice_series' => 'C26TEZ', 'label' => '2 - C26TEZ - Hóa đơn bán hàng'],
        ]];
    }

    public function submit(EInvoiceAccount $account, array $invoice): string
    {
        $tracking = 'fake-'.$invoice['reference_code'];
        Cache::put("einvoice:fake:{$tracking}", $invoice, now()->addDay());

        return $tracking;
    }

    public function check(EInvoiceAccount $account, string $trackingCode): EInvoiceResult
    {
        $invoice = Cache::get("einvoice:fake:{$trackingCode}");
        if (! is_array($invoice)) {
            return new EInvoiceResult(EInvoiceResult::FAILED, 'Không tìm thấy yêu cầu.');
        }
        if (($invoice['buyer']['name'] ?? '') === 'LỖI') {
            return new EInvoiceResult(EInvoiceResult::FAILED, 'Thông tin người mua không hợp lệ.');
        }
        $number = Cache::get("einvoice:fake:number:{$trackingCode}") ?? Cache::increment('einvoice:fake:counter');
        Cache::put("einvoice:fake:number:{$trackingCode}", $number, now()->addDay());

        return new EInvoiceResult(EInvoiceResult::SUCCESS, 'Xuất hóa đơn điện tử thành công', str_pad((string) $number, 8, '0', STR_PAD_LEFT), now()->toDateString(), 'https://example.test/einvoice/'.$invoice['reference_code'].'.pdf', null, 'issued');
    }

    public function find(EInvoiceAccount $account, string $referenceCode): ?EInvoiceResult
    {
        return Cache::has("einvoice:fake:number:fake-{$referenceCode}") ? $this->check($account, "fake-{$referenceCode}") : null;
    }
}
