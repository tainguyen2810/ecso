<?php

namespace App\Modules\Invoicing\Sales;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Payments\BankTransferGateway;
use App\Modules\Invoicing\Models\EInvoiceOrder;
use App\Modules\Invoicing\Models\EInvoicePackage;
use App\Modules\Invoicing\Services\EInvoiceService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Mua gói hóa đơn": EzChat Bán hàng resells e-invoice packages. The shop picks a package and types its company;
 * it pays by bank transfer with the order's code; a platform admin confirms the money, files the registration with
 * the provider and moves the steps; when the provider gives the API account, the admin connects it for the shop
 * (the same connection as Kết nối dịch vụ). The shop stays the seller on its invoices.
 */
class EInvoiceSales
{
    public function __construct(private readonly AuditLogger $audit, private readonly EInvoiceService $einvoices) {}

    /** The order the shop is going through (not cancelled, not finished). */
    public function current(): ?EInvoiceOrder
    {
        return EInvoiceOrder::query()->whereNotIn('status', [EInvoiceOrder::CANCELLED, EInvoiceOrder::CONNECTED])->latest('id')->first();
    }

    /**
     * @param  array{package: string, signing: bool, company: array<string, mixed>}  $data
     */
    public function order(array $data): EInvoiceOrder
    {
        $package = EInvoicePackage::query()->where('ulid', $data['package'])->where('active', true)->first();
        if ($package === null) {
            throw ValidationException::withMessages(['package' => 'Gói này không còn bán. Chọn gói khác.']);
        }
        if ($this->current() !== null) {
            throw ValidationException::withMessages(['package' => 'Shop đang có một đăng ký hóa đơn chưa xong. Hủy đăng ký đó (nếu chưa chuyển khoản) trước khi mua gói khác.']);
        }
        $signing = $data['signing'] && $package->signing_price !== null;
        $amount = bcadd(bcadd((string) $package->price, (string) $package->setup_fee, 2), $signing ? (string) $package->signing_price : '0', 2);

        $order = EInvoiceOrder::query()->create([
            'package_id' => $package->id,
            'number' => 'TMP-'.Str::random(12),
            'provider' => $package->provider,
            'package_name' => $package->name,
            'invoices' => $package->invoices,
            'price' => $package->price,
            'setup_fee' => $package->setup_fee,
            'signing' => $signing,
            'signing_price' => $signing ? $package->signing_price : 0,
            'amount' => $amount,
            'transfer_code' => $this->transferCode(),
            'status' => EInvoiceOrder::AWAITING_PAYMENT,
            'company' => $data['company'],
            'steps' => [EInvoiceOrder::AWAITING_PAYMENT => now()->toIso8601String()],
            'created_by' => Auth::id(),
        ]);
        $order->forceFill(['number' => 'HDDT'.now()->format('ym').'-'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT)])->save();
        $this->audit->record('einvoice.package_ordered', $order, ['number' => $order->number, 'package' => $package->name, 'amount' => $amount]);

        return $order;
    }

    public function cancelByShop(EInvoiceOrder $order): EInvoiceOrder
    {
        if ($order->status !== EInvoiceOrder::AWAITING_PAYMENT) {
            throw ValidationException::withMessages(['order' => 'Đã chuyển khoản thì không tự hủy được. Liên hệ EzChat Bán hàng.']);
        }
        $order->forceFill(['status' => EInvoiceOrder::CANCELLED, 'cancelled_at' => now()])->save();
        $this->audit->record('einvoice.package_cancelled', $order, ['number' => $order->number]);

        return $order;
    }

    /** Admin: the money arrived (bank reference kept for the books). */
    public function confirmPayment(EInvoiceOrder $order, string $reference): EInvoiceOrder
    {
        if ($order->status !== EInvoiceOrder::AWAITING_PAYMENT) {
            throw ValidationException::withMessages(['order' => 'Đơn này đã ghi nhận tiền hoặc đã hủy.']);
        }

        return $this->move($order, EInvoiceOrder::PAID, ['paid_at' => now(), 'payment_ref' => $reference]);
    }

    /**
     * Admin: the next step, with an optional message for the shop and the link where it signs the registration.
     *
     * @param  array{shop_note?: string|null, sign_url?: string|null}  $extra
     */
    public function advance(EInvoiceOrder $order, string $status, array $extra = []): EInvoiceOrder
    {
        $from = array_search($order->status, EInvoiceOrder::STEPS, true);
        $to = array_search($status, EInvoiceOrder::STEPS, true);
        if ($from === false || $to === false || $from < 1 || $to <= $from || $status === EInvoiceOrder::CONNECTED) {
            throw ValidationException::withMessages(['status' => 'Không chuyển sang bước này được.']);
        }

        return $this->move($order, $status, array_filter($extra, fn ($v) => $v !== null));
    }

    /**
     * Admin: connects the provider account the shop got, in the shop's own Kết nối dịch vụ.
     *
     * @param  array<string, string>  $credentials
     */
    public function connect(EInvoiceOrder $order, string $environment, array $credentials): EInvoiceOrder
    {
        if (! $order->isOpen() || $order->status === EInvoiceOrder::AWAITING_PAYMENT) {
            throw ValidationException::withMessages(['order' => 'Chỉ kết nối được đơn đã thanh toán và chưa xong.']);
        }
        $tenant = Tenant::query()->findOrFail($order->tenant_id);
        TenantContext::run($tenant, fn () => $this->einvoices->connect($order->provider, $environment, $credentials));

        return $this->move($order, EInvoiceOrder::CONNECTED);
    }

    public function cancelByAdmin(EInvoiceOrder $order, string $reason): EInvoiceOrder
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['order' => 'Đơn này đã xong hoặc đã hủy.']);
        }
        $order->forceFill(['status' => EInvoiceOrder::CANCELLED, 'cancelled_at' => now(), 'shop_note' => $reason])->save();
        $this->audit->record('einvoice.package_cancelled', $order, ['number' => $order->number, 'reason' => $reason], $order->tenant_id);

        return $order;
    }

    /** @return array<string, mixed> */
    public static function bank(EInvoiceOrder $order): array
    {
        $info = app(BankTransferGateway::class)->instructions(new Invoice(['amount' => $order->amount, 'transfer_code' => $order->transfer_code]));

        return $info;
    }

    /** @return array<string, mixed> */
    public static function present(EInvoiceOrder $order, bool $admin = false): array
    {
        return [
            'id' => $order->ulid,
            'number' => $order->number,
            'provider' => $order->provider,
            'package_name' => $order->package_name,
            'invoices' => $order->invoices,
            'price' => $order->price,
            'setup_fee' => $order->setup_fee,
            'signing' => $order->signing,
            'signing_price' => $order->signing_price,
            'amount' => $order->amount,
            'transfer_code' => $order->transfer_code,
            'status' => $order->status,
            'company' => $order->company,
            'shop_note' => $order->shop_note,
            'sign_url' => $order->sign_url,
            'steps' => $order->steps ?? [],
            'paid_at' => $order->paid_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'bank' => $order->status === EInvoiceOrder::AWAITING_PAYMENT ? self::bank($order) : null,
        ] + ($admin ? [
            'payment_ref' => $order->payment_ref,
            'tenant' => $order->relationLoaded('tenant') ? ['id' => $order->tenant?->ulid, 'name' => $order->tenant?->name] : null,
        ] : []);
    }

    /** @return array<string, mixed> */
    public static function presentPackage(EInvoicePackage $p): array
    {
        return [
            'id' => $p->ulid, 'provider' => $p->provider, 'name' => $p->name, 'invoices' => $p->invoices,
            'price' => $p->price, 'setup_fee' => $p->setup_fee, 'signing_price' => $p->signing_price,
            'note' => $p->note, 'active' => $p->active, 'featured' => $p->featured, 'sort' => $p->sort,
        ];
    }

    /** @param  array<string, mixed>  $values */
    private function move(EInvoiceOrder $order, string $status, array $values = []): EInvoiceOrder
    {
        DB::transaction(function () use ($order, $status, $values) {
            $steps = $order->steps ?? [];
            $steps[$status] = now()->toIso8601String();
            $order->forceFill($values + ['status' => $status, 'steps' => $steps])->save();
        });
        $this->audit->record('einvoice.package_step', $order, ['number' => $order->number, 'status' => $status], $order->tenant_id);

        return $order;
    }

    private function transferCode(): string
    {
        do {
            $code = 'HDDT'.Str::upper(Str::random(6));
        } while (EInvoiceOrder::withoutGlobalScopes()->where('transfer_code', $code)->exists());

        return $code;
    }
}
