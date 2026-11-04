<?php

namespace App\Modules\Sales\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerDebtEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening balances and adjustments of customer debt. Each one is a numbered document with a reason; it is never
 * edited or deleted, only voided with a reason, so the debt book always traces back to its documents.
 */
class CustomerDebtEntries
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{kind: string, amount: string|int|float, reason: string, entry_date?: string|null, due_date?: string|null}  $data
     */
    public function record(Customer $customer, array $data): CustomerDebtEntry
    {
        return DB::transaction(function () use ($customer, $data) {
            $kind = $data['kind'];
            if (! in_array($kind, [CustomerDebtEntry::OPENING, CustomerDebtEntry::ADJUSTMENT], true)) {
                throw ValidationException::withMessages(['kind' => 'Loại chứng từ không hợp lệ.']);
            }
            $amount = bcadd((string) $data['amount'], '0', 2);
            if (bccomp($amount, '0', 2) === 0 || ($kind === CustomerDebtEntry::OPENING && bccomp($amount, '0', 2) < 0)) {
                throw ValidationException::withMessages(['amount' => $kind === CustomerDebtEntry::OPENING ? 'Nợ đầu kỳ phải lớn hơn 0.' : 'Số tiền điều chỉnh phải khác 0.']);
            }
            // Locks the customer so two documents cannot be numbered or checked against each other at once.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
            if ($kind === CustomerDebtEntry::OPENING && $customer->debtEntries()->where('kind', CustomerDebtEntry::OPENING)->whereNull('voided_at')->exists()) {
                throw ValidationException::withMessages(['kind' => 'Khách đã có công nợ đầu kỳ. Hủy chứng từ cũ trước khi nhập lại.']);
            }

            $entry = new CustomerDebtEntry;
            $entry->forceFill([
                'number' => DocumentNumber::next($kind === CustomerDebtEntry::OPENING ? 'NDK' : 'DCN'),
                'customer_id' => $customer->id,
                'kind' => $kind,
                'amount' => $amount,
                'entry_date' => Carbon::parse($data['entry_date'] ?? now())->toDateString(),
                'due_date' => filled($data['due_date'] ?? null) ? Carbon::parse($data['due_date'])->toDateString() : null,
                'reason' => trim($data['reason']),
                'created_by' => Auth::id(),
            ])->save();
            $this->audit->record("customer.debt_{$kind}", $customer, ['number' => $entry->number, 'amount' => $amount, 'reason' => $entry->reason]);

            return $entry;
        });
    }

    public function void(CustomerDebtEntry $entry, string $reason): CustomerDebtEntry
    {
        return DB::transaction(function () use ($entry, $reason) {
            $entry = CustomerDebtEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->voided_at !== null) {
                throw ValidationException::withMessages(['entry' => 'Chứng từ đã hủy.']);
            }
            if ($entry->cash_voucher_id !== null) {
                throw ValidationException::withMessages(['entry' => 'Phiếu thu nợ hủy ở Sổ quỹ (hủy phiếu thu tương ứng).']);
            }
            $entry->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason])->save();
            $this->audit->record('customer.debt_voided', $entry->customer, ['number' => $entry->number, 'amount' => $entry->amount, 'reason' => $reason]);

            return $entry;
        });
    }
}
