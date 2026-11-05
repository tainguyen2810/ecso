<?php

namespace App\Modules\Cashbook\Services;

use App\Modules\Cashbook\Models\CashAccount;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * One list of every receipt and payment: the vouchers entered by hand plus the ones created automatically by
 * order payments, refunds and supplier payments. Automatic rows are read-only here; they are changed on the order
 * or purchase they come from.
 */
class VoucherLedger
{
    public const SOURCES = ['voucher', 'order_payment', 'supplier_payment', 'shift_movement', 'transfer'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{direction?: string|null, status?: string|null, from?: string|null, to?: string|null, method?: string|null, type?: string|null, party_kind?: string|null, q?: string|null, account?: string|null}  $filters
     * @return array{page: LengthAwarePaginator<int, object>, totals: array{count: int, in: float, out: float}}
     */
    public function search(array $filters, int $perPage = 20): array
    {
        $account = ($filters['account'] ?? null) ? CashAccount::query()->where('ulid', $filters['account'])->value('id') : null;
        if (($filters['account'] ?? null) && ! $account) {
            return ['page' => new LengthAwarePaginator([], 0, $perPage), 'totals' => ['count' => 0, 'in' => 0.0, 'out' => 0.0]];
        }
        $query = DB::query()->fromSub($this->union($account), 'v')
            ->when($account, fn ($q) => $q->where('account_key', $account))
            ->when($filters['direction'] ?? null, fn ($q, $d) => $q->where('direction', $d))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->whereNull('voided_at'))
            ->when(($filters['status'] ?? null) === 'voided', fn ($q) => $q->whereNotNull('voided_at'))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('paid_at', '>=', ReportRange::date($d)))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('paid_at', '<=', ReportRange::date($d)->endOfDay()))
            ->when($filters['method'] ?? null, fn ($q, $m) => $q->where('method', $m))
            ->when($filters['party_kind'] ?? null, fn ($q, $k) => $q->where('party_kind', $k))
            ->when($filters['type'] ?? null, fn ($q, $t) => in_array($t, self::SOURCES, true) ? $q->where('source', $t) : $q->where('type_id', $t))
            ->when(trim((string) ($filters['q'] ?? '')), function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('party_name', 'like', $like)
                    ->orWhere('document_number', 'like', $like)->orWhere('reference', 'like', $like));
            });

        $totals = (clone $query)->whereNull('voided_at')
            ->selectRaw("COUNT(*) as n, COALESCE(SUM(CASE WHEN direction = 'receipt' THEN amount ELSE 0 END), 0) as total_in, COALESCE(SUM(CASE WHEN direction = 'payment' THEN amount ELSE 0 END), 0) as total_out")
            ->first();
        $page = $query->orderByDesc('paid_at')->orderByDesc('created_at')->paginate($perPage);

        return ['page' => $page, 'totals' => ['count' => (int) ($totals->n ?? 0), 'in' => (float) ($totals->total_in ?? 0), 'out' => (float) ($totals->total_out ?? 0)]];
    }

    /** A single row of the ledger, by source and public id. */
    public function find(string $source, string $id): ?object
    {
        return DB::query()->fromSub($this->union(), 'v')->where('source', $source)->where('id', $id)->first();
    }

    /**
     * @param  int|null  $account  with a fund, a transfer is two rows (out of one fund, into the other) so each fund's
     *                             side reads as a payment or a receipt; without, it is one row of direction "transfer",
     *                             left out of the totals (it moves money between the business's own funds)
     */
    private function union(?int $account = null): Builder
    {
        $tenant = $this->context->id();
        $columns = 'id, number, direction, type_id, type_name, counts_in_pnl, party_kind, party_id, party_name, method, amount, paid_at, '
            .'document_kind, document_id, document_number, reference, note, created_by, voided_at, void_reason, created_at, account_key, account_id, account_name, counter_account_name';

        $vouchers = DB::table('cash_vouchers as cv')
            ->join('cash_voucher_types as t', 't.id', '=', 'cv.cash_voucher_type_id')
            ->leftJoin('customers as c', 'c.id', '=', 'cv.customer_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'cv.supplier_id')
            ->leftJoin('users as u', 'u.id', '=', 'cv.created_by')
            ->leftJoin('cash_accounts as acc', 'acc.id', '=', 'cv.cash_account_id')
            ->where('cv.tenant_id', $tenant)
            ->selectRaw("'voucher' as source, cv.ulid as id, cv.number, cv.direction, t.ulid as type_id, COALESCE(cv.type_name, t.name) as type_name, cv.counts_in_pnl, cv.party_kind, "
                .'COALESCE(c.ulid, s.ulid) as party_id, cv.party_name, cv.method, cv.amount, cv.paid_at, NULL as document_kind, NULL as document_id, '
                .'NULL as document_number, cv.reference, cv.note, u.name as created_by, cv.voided_at, cv.void_reason, cv.created_at, '
                .'acc.id as account_key, acc.ulid as account_id, acc.name as account_name, NULL as counter_account_name');

        $orders = DB::table('order_payments as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'op.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'op.created_by')
            ->leftJoin('cash_accounts as acc', 'acc.id', '=', 'op.cash_account_id')
            ->where('op.tenant_id', $tenant)
            // Exchange set-offs move no money (a refund and a payment that cancel out): not cash book entries.
            ->where('op.method', '!=', OrderPayment::EXCHANGE)
            ->selectRaw("'order_payment' as source, op.ulid as id, NULL as number, CASE WHEN op.kind = 'refund' THEN 'payment' ELSE 'receipt' END as direction, "
                ."NULL as type_id, CASE WHEN op.kind = 'refund' THEN 'Hoàn tiền khách' ELSE 'Thu tiền đơn hàng' END as type_name, 0 as counts_in_pnl, "
                ."'customer' as party_kind, c.ulid as party_id, COALESCE(c.name, o.buyer_name, 'Khách lẻ') as party_name, op.method, op.amount, op.paid_at, "
                ."'order' as document_kind, o.ulid as document_id, o.external_order_id as document_number, op.reference, op.note, u.name as created_by, "
                .'op.voided_at, op.void_reason, op.created_at, acc.id as account_key, acc.ulid as account_id, acc.name as account_name, NULL as counter_account_name');

        $suppliers = DB::table('supplier_payments as sp')
            ->join('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'sp.purchase_order_id')
            ->leftJoin('users as u', 'u.id', '=', 'sp.created_by')
            ->leftJoin('cash_accounts as acc', 'acc.id', '=', 'sp.cash_account_id')
            ->where('sp.tenant_id', $tenant)
            ->selectRaw("'supplier_payment' as source, sp.ulid as id, NULL as number, CASE WHEN sp.kind = 'refund' THEN 'receipt' ELSE 'payment' END as direction, "
                ."NULL as type_id, CASE WHEN sp.kind = 'refund' THEN 'Nhà cung cấp hoàn tiền' ELSE 'Trả tiền nhà cung cấp' END as type_name, "
                ."0 as counts_in_pnl, 'supplier' as party_kind, s.ulid as party_id, s.name as party_name, sp.method, sp.amount, sp.paid_at, "
                ."CASE WHEN po.id IS NULL THEN NULL ELSE 'purchase' END as document_kind, po.ulid as document_id, po.number as document_number, "
                .'sp.reference, sp.note, u.name as created_by, sp.voided_at, sp.void_reason, sp.created_at, '
                .'acc.id as account_key, acc.ulid as account_id, acc.name as account_name, NULL as counter_account_name');

        // Cash put into or taken out of a cashier's drawer during a shift (POS "Thu/chi trong ca").
        $shiftMoves = DB::table('cash_shift_movements as m')
            ->join('cash_shifts as cs', 'cs.id', '=', 'm.cash_shift_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by')
            ->where('m.tenant_id', $tenant)
            ->whereNull('m.cash_voucher_id') // booked as a voucher: that row is listed instead
            ->selectRaw("'shift_movement' as source, CAST(m.id AS CHAR) as id, NULL as number, 'transfer' as direction, "
                ."NULL as type_id, CASE WHEN m.kind = 'out' THEN 'Rút két nộp về két tổng' ELSE 'Đưa tiền từ két tổng vào két' END as type_name, 0 as counts_in_pnl, "
                ."'staff' as party_kind, NULL as party_id, u.name as party_name, 'cash' as method, m.amount, m.created_at as paid_at, "
                ."'shift' as document_kind, cs.ulid as document_id, cs.number as document_number, NULL as reference, m.reason as note, u.name as created_by, "
                .'NULL as voided_at, NULL as void_reason, m.created_at, NULL as account_key, NULL as account_id, NULL as account_name, NULL as counter_account_name');

        $all = $vouchers->unionAll($orders)->unionAll($suppliers)->unionAll($shiftMoves);

        // Transfers between funds: one row, or one row per side when a fund is picked.
        $legs = $account ? [['payment', 'from_account_id', 'to_account_id'], ['receipt', 'to_account_id', 'from_account_id']] : [['transfer', 'from_account_id', 'to_account_id']];
        foreach ($legs as [$direction, $own, $other]) {
            $all->unionAll(DB::table('cash_transfers as tr')
                ->join('cash_accounts as acc', 'acc.id', '=', "tr.{$own}")
                ->join('cash_accounts as oth', 'oth.id', '=', "tr.{$other}")
                ->leftJoin('users as u', 'u.id', '=', 'tr.created_by')
                ->where('tr.tenant_id', $tenant)
                ->selectRaw("'transfer' as source, tr.ulid as id, tr.number, '{$direction}' as direction, NULL as type_id, 'Chuyển quỹ' as type_name, 0 as counts_in_pnl, "
                    ."'other' as party_kind, NULL as party_id, CONCAT(acc.name, ' → ', oth.name) as party_name, 'transfer' as method, tr.amount, tr.transferred_at as paid_at, "
                    ."'transfer' as document_kind, tr.ulid as document_id, tr.number as document_number, tr.reference, tr.note, u.name as created_by, "
                    .'tr.voided_at, tr.void_reason, tr.created_at, acc.id as account_key, acc.ulid as account_id, acc.name as account_name, oth.name as counter_account_name'));
        }

        return DB::query()->fromSub($all, 'all_vouchers')->selectRaw("source, {$columns}");
    }
}
