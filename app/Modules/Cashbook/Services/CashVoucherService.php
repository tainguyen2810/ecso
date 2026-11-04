<?php

namespace App\Modules\Cashbook\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Models\CashVoucherType;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Orders\Models\Order;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerDebtEntry;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Sales\Services\CustomerDebt;
use App\Modules\Sales\Services\PaymentService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receipt and payment vouchers, their types, and debt receipts from customers. A debt receipt is not a voucher:
 * it is split into payments of the customer's unpaid orders (oldest first), so order and customer debt stay
 * computed from one source.
 */
class CashVoucherService
{
    /** Receipt type of debt receipts that settle opening balances and adjustments (created when first needed). */
    public const DEBT_RECEIPT_TYPE = 'Thu nợ khách hàng';

    /** Profit lines a payment type can feed (null = other): direct selling costs and costs of running the business. */
    public const PNL_GROUPS = ['sales', 'operating'];

    /**
     * Types every business starts with: [direction, name, counts_in_pnl, pnl_group]. "Tiền sàn chuyển về" is money the
     * marketplace pays out for orders whose revenue is already counted, so it is not income.
     */
    public const DEFAULT_TYPES = [
        [CashVoucher::RECEIPT, 'Thu nhập khác', true, null],
        [CashVoucher::RECEIPT, 'Tiền bồi thường', true, null],
        [CashVoucher::RECEIPT, 'Thanh lý tài sản', true, null],
        [CashVoucher::RECEIPT, 'Cho thuê tài sản', true, null],
        [CashVoucher::RECEIPT, 'Tiền sàn chuyển về', false, null],
        [CashVoucher::RECEIPT, 'Chủ góp vốn', false, null],
        [CashVoucher::PAYMENT, 'Chi phí quảng cáo', true, 'sales'],
        [CashVoucher::PAYMENT, 'Phí vận chuyển', true, 'sales'],
        [CashVoucher::PAYMENT, 'Vật tư đóng gói', true, 'sales'],
        [CashVoucher::PAYMENT, 'Lương nhân viên', true, 'operating'],
        [CashVoucher::PAYMENT, 'Thuê mặt bằng', true, 'operating'],
        [CashVoucher::PAYMENT, 'Điện, nước, internet', true, 'operating'],
        [CashVoucher::PAYMENT, 'Phần mềm, dịch vụ', true, 'operating'],
        [CashVoucher::PAYMENT, 'Phí ngân hàng', true, 'operating'],
        [CashVoucher::PAYMENT, 'Thuế, phí', true, 'operating'],
        [CashVoucher::PAYMENT, 'Chi phí khác', true, 'operating'],
        [CashVoucher::PAYMENT, 'Chủ rút vốn', false, null],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PaymentService $payments,
        private readonly TenantContext $context,
        private readonly CustomerDebt $debt,
    ) {}

    /** Creates the default types the first time a business opens its voucher types. */
    public function ensureDefaultTypes(): void
    {
        if (CashVoucherType::query()->exists()) {
            return;
        }
        DB::transaction(function () {
            foreach (self::DEFAULT_TYPES as [$direction, $name, $pnl, $group]) {
                CashVoucherType::query()->firstOrCreate(['direction' => $direction, 'name' => $name], ['counts_in_pnl' => $pnl, 'pnl_group' => $group]);
            }
        });
    }

    /**
     * @param  array{direction: string, name: string, counts_in_pnl?: bool, pnl_group?: string|null, description?: string|null}  $data
     */
    public function createType(array $data): CashVoucherType
    {
        $this->assertUniqueName($data['direction'], $data['name']);
        $type = CashVoucherType::query()->create([
            'direction' => $data['direction'],
            'name' => trim($data['name']),
            'counts_in_pnl' => $data['counts_in_pnl'] ?? true,
            'pnl_group' => $data['direction'] === CashVoucher::PAYMENT ? ($data['pnl_group'] ?? 'operating') : null,
            'description' => $data['description'] ?? null,
        ]);
        $this->audit->record('cash.type_created', $type, ['name' => $type->name, 'direction' => $type->direction]);

        return $type;
    }

    /**
     * @param  array{name?: string, counts_in_pnl?: bool, pnl_group?: string|null, description?: string|null, is_active?: bool}  $data
     */
    public function updateType(CashVoucherType $type, array $data): CashVoucherType
    {
        if ($type->direction !== CashVoucher::PAYMENT) {
            unset($data['pnl_group']);
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $this->assertUniqueName($type->direction, $data['name'], $type->id);
        }
        $type->fill($data);
        $changes = $type->getDirty();
        $type->save();
        $this->audit->record('cash.type_updated', $type, $changes);

        return $type;
    }

    /**
     * @param  array{direction: string, type: string, party_kind: string, party_id?: string|null, party_name?: string|null, method: string, amount: string|int|float, counts_in_pnl?: bool|null, reference?: string|null, note?: string|null, paid_at?: string|null}  $data
     */
    public function record(array $data): CashVoucher
    {
        return DB::transaction(function () use ($data) {
            $type = CashVoucherType::query()->where('ulid', $data['type'])->first();
            if (! $type || $type->direction !== $data['direction']) {
                throw ValidationException::withMessages(['type' => 'Loại phiếu không hợp lệ.']);
            }
            if (! $type->is_active) {
                throw ValidationException::withMessages(['type' => 'Loại phiếu này đã ngừng dùng.']);
            }
            $amount = bcadd((string) $data['amount'], '0', 2);
            if (bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Số tiền phải lớn hơn 0.']);
            }

            $voucher = new CashVoucher;
            $voucher->forceFill([
                'number' => DocumentNumber::next($data['direction'] === CashVoucher::RECEIPT ? 'PT' : 'PC'),
                'direction' => $data['direction'],
                'cash_voucher_type_id' => $type->id,
                'party_kind' => $data['party_kind'],
                ...$this->party($data['party_kind'], $data['party_id'] ?? null, $data['party_name'] ?? null),
                'method' => $data['method'],
                'amount' => $amount,
                'counts_in_pnl' => $data['counts_in_pnl'] ?? $type->counts_in_pnl,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                'created_by' => Auth::id(),
            ])->save();
            $this->audit->record("cash.{$voucher->direction}", $voucher, ['number' => $voucher->number, 'amount' => $amount, 'type' => $type->name]);

            return $voucher;
        });
    }

    public function void(CashVoucher $voucher, string $reason): CashVoucher
    {
        return DB::transaction(function () use ($voucher, $reason) {
            $voucher = CashVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($voucher->voided_at !== null) {
                throw ValidationException::withMessages(['voucher' => 'Phiếu đã hủy.']);
            }
            $voucher->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason])->save();
            $this->audit->record('cash.voided', $voucher, ['number' => $voucher->number, 'amount' => $voucher->amount, 'reason' => $reason]);

            return $voucher;
        });
    }

    /**
     * The customer's orders still owing something (CustomerDebt), oldest first, each with its `debt_due`. What a
     * debt receipt can collect.
     *
     * @return Collection<int, Order>
     */
    public function unpaidOrders(Customer $customer, bool $lock = false): Collection
    {
        return $this->debt->openOrders($customer, $lock);
    }

    /**
     * One receipt from a customer: it first settles what opening balances and adjustments leave owing (as a cash
     * voucher linked to a debt receipt entry), then the unpaid orders, oldest first (as payments of those orders).
     *
     * @param  array{method: string, amount: string|int|float, reference?: string|null, note?: string|null, paid_at?: string|null}  $data
     * @return array{payments: Collection<int, OrderPayment>, voucher: CashVoucher|null}
     */
    public function collectCustomerDebt(Customer $customer, array $data): array
    {
        return DB::transaction(function () use ($customer, $data) {
            $remaining = bcadd((string) $data['amount'], '0', 2);
            if (bccomp($remaining, '0', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Số tiền phải lớn hơn 0.']);
            }
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
            $orders = $this->unpaidOrders($customer, lock: true);
            $debt = $this->debt->forCustomer($customer);
            if (bccomp($remaining, $debt, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'Số tiền vượt quá công nợ của khách ('.number_format((float) $debt, 0, ',', '.').' đ).']);
            }

            $voucher = null;
            $entries = $this->debt->entriesBalance($customer);
            if (bccomp($entries, '0', 2) > 0) {
                $take = bccomp($remaining, $entries, 2) > 0 ? $entries : $remaining;
                $type = CashVoucherType::query()->firstOrCreate(
                    ['direction' => CashVoucher::RECEIPT, 'name' => self::DEBT_RECEIPT_TYPE],
                    ['counts_in_pnl' => false, 'description' => 'Thu công nợ đầu kỳ hoặc công nợ điều chỉnh của khách hàng.'],
                );
                $voucher = $this->record([
                    'direction' => CashVoucher::RECEIPT, 'type' => $type->ulid, 'party_kind' => 'customer', 'party_id' => $customer->ulid,
                    'method' => $data['method'], 'amount' => $take, 'counts_in_pnl' => false,
                    'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? 'Thu nợ đầu kỳ / điều chỉnh', 'paid_at' => $data['paid_at'] ?? null,
                ]);
                $entry = new CustomerDebtEntry;
                $entry->forceFill([
                    'number' => $voucher->number, 'customer_id' => $customer->id, 'kind' => CustomerDebtEntry::RECEIPT,
                    'amount' => bcsub('0', $take, 2), 'entry_date' => $voucher->paid_at->toDateString(),
                    'reason' => 'Thu nợ, phiếu '.$voucher->number, 'cash_voucher_id' => $voucher->id, 'created_by' => Auth::id(),
                ])->save();
                $remaining = bcsub($remaining, $take, 2);
            }

            $payments = collect();
            foreach ($orders as $order) {
                if (bccomp($remaining, '0', 2) <= 0) {
                    break;
                }
                // What this order still owes, and never more than the payment itself allows on it.
                $due = self::due($order);
                $take = bccomp($remaining, $due, 2) > 0 ? $due : $remaining;
                if (bccomp($take, '0', 2) <= 0) {
                    continue;
                }
                $payments->push($this->payments->record($order, [
                    'method' => $data['method'],
                    'amount' => $take,
                    'reference' => $data['reference'] ?? null,
                    'note' => $data['note'] ?? 'Thu nợ khách hàng',
                    'paid_at' => $data['paid_at'] ?? null,
                ]));
                $remaining = bcsub($remaining, $take, 2);
            }
            if (bccomp($remaining, '0', 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'Công nợ còn nhận thêm được '.number_format((float) bcsub((string) $data['amount'], $remaining, 2), 0, ',', '.').' đ.']);
            }
            $this->audit->record('customer.debt_collected', $customer, ['amount' => $data['amount'], 'orders' => $payments->count(), 'voucher' => $voucher?->number]);

            return ['payments' => $payments, 'voucher' => $voucher];
        });
    }

    /** What an order from unpaidOrders() still owes and can take as a payment. */
    public static function due(Order $order): string
    {
        $due = bcadd((string) $order->getAttribute('debt_due'), '0', 2);
        $balance = $order->balanceDue();

        return bccomp($due, $balance, 2) > 0 ? $balance : $due;
    }

    /** @return array{customer_id: int|null, supplier_id: int|null, staff_id: int|null, party_name: string} */
    private function party(string $kind, ?string $id, ?string $name): array
    {
        $party = ['customer_id' => null, 'supplier_id' => null, 'staff_id' => null, 'party_name' => trim((string) $name)];
        $missing = fn () => ValidationException::withMessages(['party_id' => 'Không tìm thấy đối tượng.']);

        if ($kind === 'customer' && $id) {
            $customer = Customer::query()->where('ulid', $id)->first() ?? throw $missing();
            $party = [...$party, 'customer_id' => $customer->id, 'party_name' => $customer->name];
        } elseif ($kind === 'supplier' && $id) {
            $supplier = Supplier::query()->where('ulid', $id)->first() ?? throw $missing();
            $party = [...$party, 'supplier_id' => $supplier->id, 'party_name' => $supplier->name];
        } elseif ($kind === 'staff' && $id) {
            $user = User::query()->where('ulid', $id)
                ->whereIn('id', DB::table('tenant_users')->where('tenant_id', $this->context->id())->select('user_id'))
                ->first() ?? throw $missing();
            $party = [...$party, 'staff_id' => $user->id, 'party_name' => $user->name];
        }
        if ($party['party_name'] === '') {
            throw ValidationException::withMessages(['party_name' => 'Nhập tên người nộp hoặc người nhận.']);
        }

        return $party;
    }

    private function assertUniqueName(string $direction, string $name, ?int $except = null): void
    {
        $taken = CashVoucherType::query()->where('direction', $direction)->where('name', trim($name))
            ->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Đã có loại phiếu tên này.']);
        }
    }
}
