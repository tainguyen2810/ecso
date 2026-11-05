<?php

namespace App\Modules\Cashbook\Services;

use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every movement of money in or out of a fund that has not been voided, signed (+ in, − out), one row per fund side:
 * customer payments and refunds of own-channel orders, supplier payments, receipt and payment vouchers, and both
 * sides of transfers between funds. The cash book and the fund balances are both read from here.
 *
 * Transfers are rows with type transfer_in / transfer_out. Across all funds they add up to zero, so a book of all
 * funds leaves them out (they are neither money in nor money out of the business); a book of one fund keeps them.
 *
 * Columns: account_id, paid_at, type, label, method, amount, document, document_id, document_kind, party, reference,
 * created_by.
 */
class CashFlows
{
    public const TRANSFER_TYPES = ['transfer_in', 'transfer_out'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  int|null  $accountId  one fund (its transfers included) or every fund (transfers left out)
     */
    public function query(?int $accountId = null, ?string $method = null): Builder
    {
        $tenant = $this->context->id();
        $scoped = fn (Builder $q, string $column) => $q->when($accountId, fn ($w) => $w->where($column, $accountId));

        $customer = DB::table('order_payments as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'op.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'op.created_by')
            ->where('op.tenant_id', $tenant)->whereNull('op.voided_at')
            ->where('op.method', '!=', OrderPayment::EXCHANGE) // exchange set-offs move no money
            ->when($method, fn ($q) => $q->where('op.method', $method))
            ->selectRaw("op.cash_account_id as account_id, op.paid_at, op.kind as type, NULL as label, op.method, CASE WHEN op.kind = 'refund' THEN -op.amount ELSE op.amount END as amount, "
                ."o.external_order_id as document, o.ulid as document_id, 'order' as document_kind, COALESCE(c.name, o.buyer_name, 'Khách lẻ') as party, op.reference, u.name as created_by");
        $scoped($customer, 'op.cash_account_id');

        $supplier = DB::table('supplier_payments as sp')
            ->join('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'sp.purchase_order_id')
            ->leftJoin('users as u', 'u.id', '=', 'sp.created_by')
            ->where('sp.tenant_id', $tenant)->whereNull('sp.voided_at')
            ->when($method, fn ($q) => $q->where('sp.method', $method))
            ->selectRaw("sp.cash_account_id as account_id, sp.paid_at, CASE WHEN sp.kind = 'refund' THEN 'supplier_refund' ELSE 'supplier' END as type, NULL as label, sp.method, CASE WHEN sp.kind = 'refund' THEN sp.amount ELSE -sp.amount END as amount, po.number as document, po.ulid as document_id, "
                ."'purchase' as document_kind, s.name as party, sp.reference, u.name as created_by");
        $scoped($supplier, 'sp.cash_account_id');

        $vouchers = DB::table('cash_vouchers as cv')
            ->join('cash_voucher_types as t', 't.id', '=', 'cv.cash_voucher_type_id')
            ->leftJoin('users as u', 'u.id', '=', 'cv.created_by')
            ->where('cv.tenant_id', $tenant)->whereNull('cv.voided_at')
            ->when($method, fn ($q) => $q->where('cv.method', $method))
            ->selectRaw("cv.cash_account_id as account_id, cv.paid_at, 'voucher' as type, t.name as label, cv.method, CASE WHEN cv.direction = 'payment' THEN -cv.amount ELSE cv.amount END as amount, "
                ."cv.number as document, cv.ulid as document_id, 'voucher' as document_kind, cv.party_name as party, cv.reference, u.name as created_by");
        $scoped($vouchers, 'cv.cash_account_id');

        $all = $customer->unionAll($supplier)->unionAll($vouchers);

        // A transfer has no payment method of its own; a book filtered by method leaves it out.
        if ($accountId && ! $method) {
            foreach ([['out', 'from_account_id', 'to_account_id', '-'], ['in', 'to_account_id', 'from_account_id', '']] as [$leg, $own, $other, $sign]) {
                $all->unionAll(DB::table('cash_transfers as tr')
                    ->join('cash_accounts as other', 'other.id', '=', "tr.{$other}")
                    ->leftJoin('users as u', 'u.id', '=', 'tr.created_by')
                    ->where('tr.tenant_id', $tenant)->whereNull('tr.voided_at')->where("tr.{$own}", $accountId)
                    ->selectRaw("tr.{$own} as account_id, tr.transferred_at as paid_at, 'transfer_{$leg}' as type, other.name as label, 'transfer' as method, {$sign}tr.amount as amount, "
                        ."tr.number as document, tr.ulid as document_id, 'transfer' as document_kind, other.name as party, tr.reference, u.name as created_by"));
            }
        }

        return DB::query()->fromSub($all, 'flows');
    }

    /**
     * Balance of every fund (its opening balance plus every movement up to $at), keyed by account id.
     *
     * @return array<int, string>
     */
    public function balances(?\DateTimeInterface $at = null): array
    {
        $tenant = $this->context->id();
        $sums = [];
        $add = function ($rows) use (&$sums) {
            foreach ($rows as $r) {
                if ($r->account_id !== null) {
                    $sums[(int) $r->account_id] = bcadd($sums[(int) $r->account_id] ?? '0', (string) $r->total, 2);
                }
            }
        };

        $add(DB::table('cash_accounts')->where('tenant_id', $tenant)->selectRaw('id as account_id, opening_balance as total')->get());
        $add((clone $this->query())->when($at, fn ($q) => $q->where('paid_at', '<=', $at))->groupBy('account_id')->selectRaw('account_id, SUM(amount) as total')->get());
        foreach ([['from_account_id', '-'], ['to_account_id', '']] as [$column, $sign]) {
            $add(DB::table('cash_transfers')->where('tenant_id', $tenant)->whereNull('voided_at')->when($at, fn ($q) => $q->where('transferred_at', '<=', $at))
                ->groupBy($column)->selectRaw("{$column} as account_id, {$sign}SUM(amount) as total")->get());
        }

        return $sums;
    }
}
