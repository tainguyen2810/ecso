<?php

namespace App\Modules\Invoicing\Providers\SePay;

use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Invoicing\Providers\EInvoiceException;
use App\Modules\Invoicing\Providers\EInvoiceProvider;
use App\Modules\Invoicing\Providers\EInvoiceResult;

/**
 * SePay eInvoice. The shop registers at SePay (my.sepay.vn), which files invoices through the provider account it
 * set up (Mắt Bão...). Invoices are issued asynchronously: create returns a tracking code, polled until Success or
 * Failed. Our reference code is unique per invoice, so asking again never makes a second one.
 */
class SePayProvider implements EInvoiceProvider
{
    public const KEY = 'sepay';

    public function __construct(private readonly SePayClient $client) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'SePay eInvoice';
    }

    public function credentialFields(): array
    {
        return ['client_id' => 'Client ID', 'client_secret' => 'Client Secret'];
    }

    public function connect(array $credentials, string $environment): array
    {
        $who = ['credentials' => $credentials, 'environment' => $environment];
        $this->client->token($credentials, $environment, true);
        $accounts = $this->client->call($who, 'GET', '/v1/provider-accounts', ['per_page' => 100]);
        $items = collect($accounts['items'] ?? [])->filter(fn ($a) => is_array($a) && ($a['active'] ?? false) === true)->values();
        if ($items->isEmpty()) {
            throw new EInvoiceException('Tài khoản SePay chưa có nhà cung cấp hóa đơn nào đang hoạt động. Hoàn tất đăng ký hóa đơn ở my.sepay.vn trước.');
        }
        $first = $items->first();
        $detail = $this->client->call($who, 'GET', '/v1/provider-accounts/'.rawurlencode((string) $first['id']));
        $templates = collect($detail['templates'] ?? [])
            ->filter(fn ($t) => is_array($t) && filled($t['template_code'] ?? null) && filled($t['invoice_series'] ?? null))
            ->map(fn ($t) => [
                'template_code' => (string) $t['template_code'],
                'invoice_series' => (string) $t['invoice_series'],
                'label' => (string) ($t['invoice_label'] ?? $t['template_code'].' - '.$t['invoice_series']),
            ])->values()->all();
        if ($templates === []) {
            throw new EInvoiceException('Tài khoản SePay chưa có mẫu số / ký hiệu hóa đơn nào được duyệt.');
        }

        return ['account' => (string) $first['id'], 'provider_name' => isset($first['provider']) ? (string) $first['provider'] : null, 'templates' => $templates];
    }

    public function submit(EInvoiceAccount $account, array $invoice): string
    {
        $data = $this->client->call($account, 'POST', '/v1/invoices/create', ['provider_account_id' => $account->external_account, 'is_draft' => false] + $invoice, sideEffect: true);
        $tracking = (string) ($data['tracking_code'] ?? '');
        if ($tracking === '') {
            throw new EInvoiceException('SePay không trả mã theo dõi. Bấm "Kiểm tra lại" để kiểm tra hóa đơn.', uncertain: true);
        }

        return $tracking;
    }

    public function check(EInvoiceAccount $account, string $trackingCode): EInvoiceResult
    {
        return $this->result((array) $this->client->call($account, 'GET', '/v1/invoices/create/check/'.rawurlencode($trackingCode)));
    }

    public function find(EInvoiceAccount $account, string $referenceCode): ?EInvoiceResult
    {
        $data = $this->client->call($account, 'GET', '/v1/invoices/'.rawurlencode($referenceCode), allowMissing: true);
        if ($data === null) {
            return null;
        }
        $invoice = isset($data['invoice']) && is_array($data['invoice']) ? $data['invoice'] : $data;

        return $this->invoiceResult($invoice, EInvoiceResult::SUCCESS, null);
    }

    /** @param  array<string, mixed>  $data */
    private function result(array $data): EInvoiceResult
    {
        $state = match (strtolower((string) ($data['status'] ?? ''))) {
            'success' => EInvoiceResult::SUCCESS,
            'failed' => EInvoiceResult::FAILED,
            default => EInvoiceResult::PENDING,
        };
        $message = isset($data['message']) ? mb_substr((string) $data['message'], 0, 500) : null;
        if ($state !== EInvoiceResult::SUCCESS || ! is_array($data['invoice'] ?? null)) {
            return new EInvoiceResult($state, $message);
        }

        return $this->invoiceResult($data['invoice'], $state, $message);
    }

    /** @param  array<string, mixed>  $invoice */
    private function invoiceResult(array $invoice, string $state, ?string $message): EInvoiceResult
    {
        $number = isset($invoice['invoice_number']) ? (string) $invoice['invoice_number'] : null;

        return new EInvoiceResult(
            $state,
            $message,
            $number === '0' || $number === '' ? null : $number,
            isset($invoice['issued_date']) ? (string) $invoice['issued_date'] : null,
            isset($invoice['pdf_url']) && is_string($invoice['pdf_url']) ? $invoice['pdf_url'] : null,
            isset($invoice['xml_url']) && is_string($invoice['xml_url']) ? $invoice['xml_url'] : null,
            isset($invoice['status']) ? (string) $invoice['status'] : null,
        );
    }
}
