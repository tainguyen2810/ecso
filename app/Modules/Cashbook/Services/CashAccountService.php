<?php

namespace App\Modules\Cashbook\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashbook\Models\CashAccount;
use App\Modules\Cashbook\Models\CashTransfer;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Models\CashVoucherType;
use App\Modules\Cashbook\Support\DefaultCashAccounts;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Funds and transfers between them. Every money row (voucher, order payment, supplier payment) is put in a fund when
 * it is recorded: the one picked, or the default fund of its payment method.
 */
class CashAccountService
{
    /** Name of the payment type a transfer's bank fee is booked under (created when first needed). */
    public const FEE_TYPE = 'Phí ngân hàng';

    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /** @return Collection<int, CashAccount> */
    public function all(): Collection
    {
        DefaultCashAccounts::ensure($this->context->id());

        return CashAccount::query()->orderByDesc('is_active')->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * The fund a money row goes into: the one picked (by public id), else the default fund of the payment method.
     * Null for exchange set-offs, which move no money.
     */
    public function resolve(?string $account, string $method): ?int
    {
        if ($account) {
            $picked = CashAccount::query()->where('ulid', $account)->first();
            if (! $picked) {
                throw ValidationException::withMessages(['account' => 'Quỹ không tồn tại.']);
            }
            if (! $picked->is_active) {
                throw ValidationException::withMessages(['account' => 'Quỹ này đã ngừng dùng.']);
            }

            return $picked->id;
        }
        if ($method === 'exchange') {
            return null;
        }
        $map = DefaultCashAccounts::ensure($this->context->id());

        return $map[$method] ?? $map['other'] ?? null;
    }

    /**
     * @param  array{kind: string, name: string, bank_name?: string|null, account_number?: string|null, holder?: string|null, methods?: array<int, string>, opening_balance?: string|int|float|null, opening_at?: string|null, note?: string|null}  $data
     */
    public function create(array $data): CashAccount
    {
        DefaultCashAccounts::ensure($this->context->id());

        return DB::transaction(function () use ($data) {
            $this->assertUniqueName($data['name']);
            $account = new CashAccount([
                'kind' => $data['kind'],
                'name' => trim($data['name']),
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'holder' => $data['holder'] ?? null,
                'methods' => [],
                'opening_balance' => bcadd((string) ($data['opening_balance'] ?? 0), '0', 2),
                'opening_at' => $data['opening_at'] ?? null,
                'note' => $data['note'] ?? null,
                'sort' => (int) CashAccount::query()->max('sort') + 1,
                'is_active' => true,
            ]);
            $account->save();
            $this->takeMethods($account, $data['methods'] ?? []);
            $this->audit->record('cash.account_created', $account, ['name' => $account->name, 'opening_balance' => $account->opening_balance]);

            return $account->refresh();
        });
    }

    /**
     * @param  array{name?: string, bank_name?: string|null, account_number?: string|null, holder?: string|null, methods?: array<int, string>, opening_balance?: string|int|float|null, opening_at?: string|null, note?: string|null, is_active?: bool}  $data
     */
    public function update(CashAccount $account, array $data): CashAccount
    {
        return DB::transaction(function () use ($account, $data) {
            $account = CashAccount::query()->lockForUpdate()->findOrFail($account->id);
            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                $this->assertUniqueName($data['name'], $account->id);
            }
            if (array_key_exists('opening_balance', $data)) {
                $data['opening_balance'] = bcadd((string) ($data['opening_balance'] ?? 0), '0', 2);
            }
            if (($data['is_active'] ?? true) === false) {
                if ($account->methods !== []) {
                    throw ValidationException::withMessages(['is_active' => 'Quỹ đang nhận tiền mặc định của một hình thức thanh toán. Chuyển hình thức đó sang quỹ khác trước.']);
                }
                if (CashAccount::query()->where('is_active', true)->whereKeyNot($account->id)->doesntExist()) {
                    throw ValidationException::withMessages(['is_active' => 'Phải còn ít nhất một quỹ đang dùng.']);
                }
            }
            $methods = $data['methods'] ?? null;
            unset($data['methods']);
            $account->fill($data);
            $changes = $account->getDirty();
            $account->save();
            if ($methods !== null) {
                $this->takeMethods($account, $methods);
                $changes['methods'] = $methods;
            }
            $this->audit->record('cash.account_updated', $account, $changes);

            return $account->refresh();
        });
    }

    /**
     * Moves money from one fund to another. The fee, if any, is a payment voucher of the source fund under the type
     * "Phí ngân hàng", so it counts as an expense; the amount moved is neither income nor expense.
     *
     * @param  array{from: string, to: string, amount: string|int|float, fee?: string|int|float|null, transferred_at?: string|null, reference?: string|null, note?: string|null}  $data
     */
    public function transfer(array $data): CashTransfer
    {
        return DB::transaction(function () use ($data) {
            $from = CashAccount::query()->where('ulid', $data['from'])->first();
            $to = CashAccount::query()->where('ulid', $data['to'])->first();
            if (! $from || ! $to) {
                throw ValidationException::withMessages(['from' => 'Quỹ không tồn tại.']);
            }
            if ($from->id === $to->id) {
                throw ValidationException::withMessages(['to' => 'Quỹ nhận phải khác quỹ chuyển.']);
            }
            if (! $from->is_active || ! $to->is_active) {
                throw ValidationException::withMessages(['from' => 'Không chuyển với quỹ đã ngừng dùng.']);
            }
            $amount = bcadd((string) $data['amount'], '0', 2);
            $fee = bcadd((string) ($data['fee'] ?? 0), '0', 2);
            if (bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Số tiền phải lớn hơn 0.']);
            }
            if (bccomp($fee, '0', 2) < 0) {
                throw ValidationException::withMessages(['fee' => 'Phí không được âm.']);
            }
            $at = isset($data['transferred_at']) ? Carbon::parse($data['transferred_at']) : now();

            $transfer = new CashTransfer([
                'number' => DocumentNumber::next('CQ'),
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'fee' => $fee,
                'transferred_at' => $at,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
            $transfer->forceFill(['created_by' => Auth::id()])->save();

            if (bccomp($fee, '0', 2) > 0) {
                $type = CashVoucherType::query()->firstOrCreate(
                    ['direction' => CashVoucher::PAYMENT, 'name' => self::FEE_TYPE],
                    ['counts_in_pnl' => true, 'pnl_group' => 'operating', 'description' => 'Phí chuyển tiền, phí duy trì tài khoản'],
                );
                (new CashVoucher)->forceFill([
                    'number' => DocumentNumber::next('PC'),
                    'direction' => CashVoucher::PAYMENT,
                    'cash_voucher_type_id' => $type->id,
                    'cash_account_id' => $from->id,
                    'cash_transfer_id' => $transfer->id,
                    'party_kind' => 'other',
                    'party_name' => $from->bank_name ?: $from->name,
                    'method' => $from->kind === 'cash' ? 'cash' : 'bank_transfer',
                    'amount' => $fee,
                    'counts_in_pnl' => $type->counts_in_pnl,
                    'reference' => $transfer->number,
                    'note' => "Phí chuyển {$transfer->number}: {$from->name} → {$to->name}",
                    'paid_at' => $at,
                    'created_by' => Auth::id(),
                ])->save();
            }
            $this->audit->record('cash.transferred', $transfer, ['number' => $transfer->number, 'from' => $from->name, 'to' => $to->name, 'amount' => $amount, 'fee' => $fee]);

            return $transfer;
        });
    }

    /** Cancels a transfer and its fee voucher together; both stay in the history, marked voided. */
    public function voidTransfer(CashTransfer $transfer, string $reason): CashTransfer
    {
        return DB::transaction(function () use ($transfer, $reason) {
            $transfer = CashTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->voided_at !== null) {
                throw ValidationException::withMessages(['transfer' => 'Phiếu chuyển quỹ đã hủy.']);
            }
            $stamp = ['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason];
            $transfer->forceFill($stamp)->save();
            CashVoucher::query()->where('cash_transfer_id', $transfer->id)->whereNull('voided_at')->update($stamp);
            $this->audit->record('cash.transfer_voided', $transfer, ['number' => $transfer->number, 'amount' => $transfer->amount, 'reason' => $reason]);

            return $transfer;
        });
    }

    /** Gives $account the default of these payment methods, taking them off any other fund. */
    private function takeMethods(CashAccount $account, array $methods): void
    {
        $methods = array_values(array_unique($methods));
        if ($methods !== [] && ! $account->is_active) {
            throw ValidationException::withMessages(['methods' => 'Quỹ đã ngừng dùng không nhận tiền mặc định.']);
        }
        foreach (CashAccount::query()->whereKeyNot($account->id)->lockForUpdate()->get() as $other) {
            $left = array_values(array_diff($other->methods ?? [], $methods));
            if ($left !== ($other->methods ?? [])) {
                $other->forceFill(['methods' => $left])->save();
            }
        }
        $account->forceFill(['methods' => $methods])->save();
    }

    private function assertUniqueName(string $name, ?int $except = null): void
    {
        if (CashAccount::query()->where('name', trim($name))->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['name' => 'Đã có quỹ tên này.']);
        }
    }
}
