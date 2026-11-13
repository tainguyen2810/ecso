<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Invoicing\Jobs\CheckEInvoiceJob;
use App\Modules\Invoicing\Models\EInvoice;
use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Invoicing\Providers\EInvoiceException;
use App\Modules\Invoicing\Providers\EInvoiceResult;
use App\Modules\Invoicing\Providers\ProviderRegistry;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues e-invoices for orders through the shop's provider account (C2). One issued invoice per order: a request
 * that failed is asked again with the same reference code (the provider never makes two), one still pending is
 * only checked. Corrections (adjust / replace) are done in the provider's portal for now.
 */
class EInvoiceService
{
    public function __construct(private readonly ProviderRegistry $providers, private readonly AuditLogger $audit) {}

    public function account(): ?EInvoiceAccount
    {
        return EInvoiceAccount::query()->first();
    }

    /** Checks the credentials with the provider and saves the connection with the templates it allows. */
    public function connect(string $provider, string $environment, array $credentials): EInvoiceAccount
    {
        $info = $this->callProvider(fn () => $this->providers->get($provider)->connect($credentials, $environment), 'credentials');
        $account = $this->account() ?? new EInvoiceAccount;
        $keep = collect($info['templates'])->first(fn ($t) => $t['template_code'] === $account->template_code && $t['invoice_series'] === $account->invoice_series);
        $default = $keep ?? $info['templates'][0];
        $account->fill([
            'provider' => $provider,
            'environment' => $environment,
            'credentials' => $credentials,
            'external_account' => $info['account'],
            'provider_name' => $info['provider_name'],
            'templates' => $info['templates'],
            'template_code' => $default['template_code'],
            'invoice_series' => $default['invoice_series'],
            'status' => EInvoiceAccount::CONNECTED,
            'last_error' => null,
            'last_verified_at' => now(),
        ])->save();
        $this->audit->record('einvoice.connected', $account, ['provider' => $provider, 'environment' => $environment]);

        return $account;
    }

    /**
     * Saves the template (sent as "code|series"), the issue mode and the e-mail choice.
     *
     * @param  array{template?: string, issue_mode?: string, send_email?: bool}  $data
     */
    public function updateSettings(EInvoiceAccount $account, array $data): EInvoiceAccount
    {
        if (isset($data['template'])) {
            $match = collect($account->templates ?? [])->first(fn ($t) => $data['template'] === $t['template_code'].'|'.$t['invoice_series']);
            if ($match === null) {
                throw ValidationException::withMessages(['template' => 'Mẫu số / ký hiệu này không có trong tài khoản hóa đơn.']);
            }
            $account->template_code = $match['template_code'];
            $account->invoice_series = $match['invoice_series'];
        }
        if (isset($data['issue_mode'])) {
            $account->issue_mode = $data['issue_mode'];
        }
        if (array_key_exists('send_email', $data)) {
            $account->send_email = (bool) $data['send_email'];
        }
        $account->save();
        $this->audit->record('einvoice.settings', $account, $account->only(['template_code', 'invoice_series', 'issue_mode', 'send_email']));

        return $account;
    }

    /** Forgets the credentials; invoices already issued stay on their orders. */
    public function disconnect(EInvoiceAccount $account): void
    {
        $account->forceFill(['credentials' => null, 'status' => 'disconnected', 'issue_mode' => 'manual'])->save();
        $this->audit->record('einvoice.disconnected', $account, ['provider' => $account->provider]);
    }

    /**
     * Who the invoice is for, from the order's customer (the invoice fields first). A walk-in sale without a name is
     * issued to "Người mua không lấy hóa đơn", as the tax rules allow.
     *
     * @return array{name: string, legal_name: string|null, tax_code: string|null, address: string|null, email: string|null, phone: string|null}
     */
    public function buyerFor(Order $order): array
    {
        /** @var Customer|null $customer */
        $customer = $order->customer_id ? Customer::query()->find($order->customer_id) : null;
        $company = filled($customer?->tax_code);
        $name = $company ? ($customer->invoice_name ?: $customer->name) : ($customer->name ?? $order->buyer_name);

        return [
            'name' => filled($name) ? (string) $name : 'Người mua không lấy hóa đơn',
            'legal_name' => $company ? ($customer->invoice_name ?: $customer->name) : null,
            'tax_code' => $customer?->tax_code,
            'address' => $customer?->invoice_address ?: ($customer?->address ?: ($order->isManual() ? $order->shipping_address : null)),
            'email' => $customer?->invoice_email ?: ($customer?->email ?: $order->buyer_email),
            'phone' => $order->isManual() ? $order->buyer_phone : null,
        ];
    }

    /**
     * Asks the provider to issue the order's invoice.
     *
     * @param  array<string, mixed>|null  $buyer  as typed on the order page; null = from the customer
     */
    public function issue(Order $order, ?array $buyer = null): EInvoice
    {
        $account = $this->account();
        if (! $account || ! $account->isUsable()) {
            throw ValidationException::withMessages(['einvoice' => 'Chưa kết nối hóa đơn điện tử. Vào Cấu hình → Kết nối dịch vụ.']);
        }
        if ($order->status === OrderStatus::CANCELLED) {
            throw ValidationException::withMessages(['einvoice' => 'Đơn đã hủy, không xuất hóa đơn.']);
        }
        if (bccomp(bcsub((string) $order->total, (string) $order->refunded_amount, 2), '0', 2) <= 0) {
            throw ValidationException::withMessages(['einvoice' => 'Đơn không còn số tiền nào để xuất hóa đơn.']);
        }

        $invoice = DB::transaction(function () use ($order, $buyer, $account) {
            Order::query()->whereKey($order->id)->lockForUpdate()->first();
            $existing = EInvoice::query()->where('order_id', $order->id)->latest('id')->first();
            if ($existing?->status === EInvoice::ISSUED) {
                throw ValidationException::withMessages(['einvoice' => "Đơn đã có hóa đơn số {$existing->invoice_number}."]);
            }
            if ($existing?->status === EInvoice::PENDING) {
                throw ValidationException::withMessages(['einvoice' => 'Hóa đơn của đơn này đang chờ nhà cung cấp xử lý. Bấm "Kiểm tra lại" để xem kết quả.']);
            }
            $data = array_merge($this->buyerFor($order), array_filter($buyer ?? [], fn ($v) => $v !== null));
            if (! $account->send_email) {
                $data['email'] = null;
            }
            $values = [
                'einvoice_account_id' => $account->id,
                'provider' => $account->provider,
                'status' => EInvoice::PENDING,
                'template_code' => $account->template_code,
                'invoice_series' => $account->invoice_series,
                'buyer' => $data,
                'total' => bcsub((string) $order->total, (string) $order->refunded_amount, 2),
                'message' => null,
                'checks' => 0,
                'created_by' => Auth::id(),
            ];
            // A failed request is asked again with its own reference code: the provider never issues it twice.
            if ($existing) {
                $existing->fill($values)->save();

                return $existing;
            }

            return EInvoice::query()->create($values + ['order_id' => $order->id, 'reference_code' => 'EZ'.Str::upper((string) Str::ulid())]);
        });

        $provider = $this->providers->get($account->provider);
        try {
            if ($invoice->wasRecentlyCreated === false && ($found = $provider->find($account, $invoice->reference_code)) !== null) {
                $this->apply($invoice, $found);
            } else {
                $order->loadMissing('items.variant.product');
                $tracking = $provider->submit($account, InvoicePayload::build($order, $account, (array) $invoice->buyer, $invoice->reference_code));
                $invoice->forceFill(['tracking_code' => $tracking])->save();
                CheckEInvoiceJob::dispatch($invoice->id)->delay(now()->addSeconds(3))->afterCommit();
            }
        } catch (EInvoiceException $e) {
            // Uncertain: it may exist at the provider, so it stays pending and "Kiểm tra lại" looks it up by reference.
            $invoice->forceFill(['status' => $e->uncertain ? EInvoice::PENDING : EInvoice::FAILED, 'message' => $e->getMessage()])->save();
            if ($e->authFailed) {
                $account->forceFill(['status' => EInvoiceAccount::ERROR, 'last_error' => $e->getMessage()])->save();
            }
        }
        $this->audit->record('einvoice.requested', $order, ['order' => $order->external_order_id, 'reference' => $invoice->reference_code, 'status' => $invoice->status]);

        return $invoice->refresh();
    }

    /** Asks the provider where a pending invoice stands. */
    public function refresh(EInvoice $invoice): EInvoice
    {
        if ($invoice->status !== EInvoice::PENDING) {
            return $invoice;
        }
        $account = $invoice->account;
        $provider = $this->providers->get($invoice->provider);
        $invoice->increment('checks');
        try {
            $result = $invoice->tracking_code
                ? $provider->check($account, $invoice->tracking_code)
                : ($provider->find($account, $invoice->reference_code) ?? new EInvoiceResult(EInvoiceResult::FAILED, 'Nhà cung cấp chưa nhận được yêu cầu. Bấm "Xuất hóa đơn" để gửi lại.'));
        } catch (EInvoiceException $e) {
            $invoice->forceFill(['message' => $e->getMessage()])->save();

            return $invoice;
        }

        return $this->apply($invoice, $result);
    }

    public function apply(EInvoice $invoice, EInvoiceResult $result): EInvoice
    {
        if ($result->state === EInvoiceResult::SUCCESS) {
            $invoice->forceFill([
                'status' => EInvoice::ISSUED,
                'invoice_number' => $result->invoiceNumber ?? $invoice->invoice_number,
                'issued_at' => $result->issuedDate ? Carbon::parse($result->issuedDate) : now(),
                'pdf_url' => $result->pdfUrl,
                'xml_url' => $result->xmlUrl,
                'message' => null,
            ])->save();
            $this->audit->record('einvoice.issued', $invoice->order, ['reference' => $invoice->reference_code, 'number' => $invoice->invoice_number]);
        } elseif ($result->state === EInvoiceResult::FAILED) {
            $invoice->forceFill(['status' => EInvoice::FAILED, 'message' => $result->message ?? 'Nhà cung cấp từ chối hóa đơn.'])->save();
        }

        return $invoice;
    }

    /** "Tự xuất": called (queued) when an own-channel order is fully paid or completed. Never throws. */
    public function autoIssue(Order $order, string $trigger): void
    {
        $account = $this->account();
        if (! $account || ! $account->isUsable() || $account->issue_mode !== $trigger || ! $order->isManual()) {
            return;
        }
        if (EInvoice::query()->where('order_id', $order->id)->whereIn('status', [EInvoice::ISSUED, EInvoice::PENDING])->exists()) {
            return;
        }
        try {
            $this->issue($order);
        } catch (ValidationException) {
            // Cancelled, nothing to invoice, already asked: nothing to do automatically.
        }
    }

    /** @return array<string, mixed> */
    public static function present(EInvoice $invoice): array
    {
        return [
            'id' => $invoice->ulid,
            'status' => $invoice->status,
            'reference_code' => $invoice->reference_code,
            'template_code' => $invoice->template_code,
            'invoice_series' => $invoice->invoice_series,
            'invoice_number' => $invoice->invoice_number,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'pdf_url' => $invoice->pdf_url,
            'buyer' => $invoice->buyer,
            'total' => $invoice->total,
            'message' => $invoice->message,
            'created_at' => $invoice->created_at?->toIso8601String(),
        ];
    }

    private function callProvider(callable $call, string $field): mixed
    {
        try {
            return $call();
        } catch (EInvoiceException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
