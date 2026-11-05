<?php

namespace App\Modules\Cashbook\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashbook\Models\CashAccount;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Models\CashVoucherType;
use App\Modules\Cashbook\Models\CodReconciliation;
use App\Modules\Cashbook\Models\CodReconciliationLine;
use App\Modules\Cashbook\Support\PeriodLock;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Services\PaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * COD reconciliation (đối soát COD) from a carrier's statement file.
 *
 * The file is read by its column headers, whatever the carrier: GHN, Viettel Post, SPX and J&T name them
 * differently, so each column is recognised by a list of usual names (tracking number, COD collected, fees). Each
 * parcel is matched to an own-channel COD order by its tracking number (or its order number).
 *
 * Confirming books, in one go: an order payment (method COD) for what each order still owed, into the fund the carrier
 * paid into, and one payment voucher "Phí vận chuyển" for the carrier's fees from that fund. The fund then moves by
 * COD − fees, what the carrier transferred.
 */
class CodReconciliationService
{
    public const MAX_LINES = 5000;

    /** Usual header names (without accents, lower case) of each column in carriers' statements. */
    private const COLUMNS = [
        'tracking' => ['ma van don', 'so van don', 'ma vd', 'ma bill', 'so bill', 'ma don hang ghn', 'ma don ghn', 'ma phieu gui', 'waybill', 'tracking', 'awb', 'order code', 'ma don hang', 'ma don'],
        'cod' => ['tien thu ho', 'thu ho', 'tien cod', 'cod amount', 'gia tri thu ho', 'tong thu ho', 'cod'],
        'fee' => ['tong phi', 'tong cuoc', 'phi van chuyen', 'cuoc phi', 'phi giao hang', 'phi dich vu', 'shipping fee', 'total fee', 'cuoc', 'phi'],
    ];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly CashVoucherService $vouchers,
        private readonly CashAccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The parcels of a statement file: the first row whose cells name a tracking column and a COD column is the header.
     *
     * @return list<array{tracking: string, cod: string, fee: string}>
     */
    public function read(string $path, string $extension): array
    {
        $reader = strtolower($extension) === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        $map = null;
        $lines = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)), $row->toArray());
                    if ($map === null) {
                        $map = $this->header($cells);

                        continue;
                    }
                    $tracking = trim($cells[$map['tracking']] ?? '');
                    if ($tracking === '') {
                        continue;
                    }
                    if (count($lines) >= self::MAX_LINES) {
                        throw ValidationException::withMessages(['file' => 'File có hơn '.self::MAX_LINES.' dòng. Tách file theo từng phiên đối soát.']);
                    }
                    $lines[] = [
                        'tracking' => mb_substr($tracking, 0, 100),
                        'cod' => self::number($cells[$map['cod']] ?? ''),
                        'fee' => $map['fee'] === null ? '0.00' : self::number($cells[$map['fee']] ?? ''),
                    ];
                }
                break; // the first sheet only
            }
        } finally {
            $reader->close();
        }
        if ($map === null) {
            throw ValidationException::withMessages(['file' => 'Không tìm thấy dòng tiêu đề có cột mã vận đơn và cột tiền thu hộ (COD).']);
        }

        return $lines;
    }

    /**
     * What confirming would do with each line, without writing anything.
     *
     * @param  list<array{tracking: string, cod: string|int|float, fee?: string|int|float|null}>  $lines
     * @return array{lines: list<array<string, mixed>>, totals: array{cod: string, fee: string, booked: string, transferred: string, matched: int, problems: int}}
     */
    public function preview(array $lines): array
    {
        $owed = []; // what each order still owes after the lines above it
        $out = [];
        foreach ($lines as $line) {
            $out[] = $this->match($line, $owed);
        }

        return ['lines' => $out, 'totals' => $this->totals($out)];
    }

    /**
     * Books the statement.
     *
     * @param  array{carrier: string, account?: string|null, remitted_at?: string|null, file_name?: string|null, reference?: string|null, note?: string|null, lines: list<array{tracking: string, cod: string|int|float, fee?: string|int|float|null}>}  $data
     */
    public function confirm(array $data): CodReconciliation
    {
        return DB::transaction(function () use ($data) {
            $remittedAt = isset($data['remitted_at']) ? Carbon::parse($data['remitted_at']) : now();
            PeriodLock::assertOpen($remittedAt, 'remitted_at');
            $accountId = $this->accounts->resolve($data['account'] ?? null, 'bank_transfer');
            $account = $accountId ? CashAccount::query()->find($accountId) : null;

            $rec = new CodReconciliation;
            $rec->forceFill([
                'number' => DocumentNumber::next('DS'),
                'carrier' => $data['carrier'],
                'file_name' => $data['file_name'] ?? null,
                'cash_account_id' => $accountId,
                'remitted_at' => $remittedAt,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => Auth::id(),
            ])->save();

            $owed = [];
            $rows = [];
            foreach ($data['lines'] as $line) {
                $m = $this->match($line, $owed, lock: true);
                $payment = null;
                if (bccomp($m['booked'], '0', 2) > 0) {
                    $payment = $this->payments->record(Order::query()->findOrFail($m['order_key']), [
                        'method' => 'cod',
                        'amount' => $m['booked'],
                        'account' => $account?->ulid,
                        'paid_at' => $remittedAt->toIso8601String(),
                        'reference' => $rec->number,
                        'note' => 'Đối soát COD '.CodReconciliation::CARRIERS[$rec->carrier].' · '.$m['tracking'],
                    ]);
                }
                $rows[] = $m;
                CodReconciliationLine::query()->create([
                    'cod_reconciliation_id' => $rec->id,
                    'tracking_number' => $m['tracking'],
                    'cod_amount' => $m['cod'],
                    'fee' => $m['fee'],
                    'order_id' => $m['order_key'],
                    'order_payment_id' => $payment?->id,
                    'status' => $m['status'],
                    'booked' => $m['booked'],
                    'note' => $m['note'],
                ]);
            }
            $totals = $this->totals($rows);

            $fee = null;
            if (bccomp($totals['fee'], '0', 2) > 0) {
                $this->vouchers->ensureDefaultTypes();
                $type = CashVoucherType::query()->firstOrCreate(
                    ['direction' => CashVoucher::PAYMENT, 'name' => 'Phí vận chuyển'],
                    ['counts_in_pnl' => true, 'pnl_group' => 'sales'],
                );
                $fee = $this->vouchers->record([
                    'direction' => CashVoucher::PAYMENT,
                    'type' => $type->ulid,
                    'party_kind' => 'carrier',
                    'party_name' => CodReconciliation::CARRIERS[$rec->carrier],
                    'method' => 'bank_transfer',
                    'account' => $account?->ulid,
                    'amount' => $totals['fee'],
                    'reference' => $rec->number,
                    'note' => 'Phí '.CodReconciliation::CARRIERS[$rec->carrier].' trừ khi đối soát COD',
                    'paid_at' => $remittedAt->toIso8601String(),
                    'no_approval' => true, // already deducted by the carrier
                ], system: true);
            }

            $rec->forceFill([
                'total_cod' => $totals['cod'],
                'total_fee' => $totals['fee'],
                'total_booked' => $totals['booked'],
                'lines_count' => count($rows),
                'matched_count' => $totals['matched'],
                'fee_voucher_id' => $fee?->id,
            ])->save();
            $this->audit->record('cash.cod_reconciled', $rec, ['number' => $rec->number, 'carrier' => $rec->carrier, 'lines' => count($rows), 'booked' => $totals['booked'], 'fee' => $totals['fee']]);

            return $rec;
        });
    }

    /**
     * @param  array{tracking: string, cod: string|int|float, fee?: string|int|float|null}  $line
     * @param  array<int, string>  $owed  order id => still owed, updated as lines are matched
     * @return array{tracking: string, cod: string, fee: string, status: string, booked: string, note: string|null, order: array{id: string, number: string, total: string, due: string}|null, order_key: int|null}
     */
    private function match(array $line, array &$owed, bool $lock = false): array
    {
        $tracking = trim((string) $line['tracking']);
        $cod = bcadd((string) ($line['cod'] ?: '0'), '0', 2);
        $fee = bcadd((string) (($line['fee'] ?? null) ?: '0'), '0', 2);
        $result = ['tracking' => $tracking, 'cod' => $cod, 'fee' => $fee, 'status' => CodReconciliationLine::NOT_FOUND, 'booked' => '0.00', 'note' => null, 'order' => null, 'order_key' => null];

        // Manual orders and website orders: both are shipped and collected by the shop's own carrier.
        $order = Order::query()->whereIn('marketplace', [Order::SOURCE_MANUAL, PaymentService::WEBSITE])
            ->where(fn ($q) => $q->where('tracking_number', $tracking)->orWhere('external_order_id', $tracking))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderByRaw('tracking_number = ? DESC', [$tracking])->first();
        if (! $order) {
            $result['note'] = 'Không tìm thấy đơn tự bán hoặc đơn website có mã vận đơn này.';

            return $result;
        }
        $owed[$order->id] ??= $order->isManual() ? $order->balanceDue() : $this->payments->unconfirmed($order);
        $due = $owed[$order->id];
        $result['order'] = ['id' => $order->ulid, 'number' => $order->external_order_id, 'total' => (string) $order->total, 'due' => $due];
        $result['order_key'] = $order->id;

        if ($order->status === OrderStatus::CANCELLED) {
            $result['note'] = 'Đơn đã hủy.';
        } elseif (bccomp($cod, '0', 2) <= 0) {
            $result['status'] = CodReconciliationLine::NO_COD;
            $result['note'] = 'Không có tiền thu hộ (thường là đơn hoàn), chỉ tính phí.';
        } elseif (bccomp($due, '0', 2) <= 0) {
            $result['status'] = CodReconciliationLine::DUPLICATE;
            $result['note'] = 'Đơn đã thu đủ (hoặc đã đối soát trước đó).';
        } else {
            $booked = bccomp($cod, $due, 2) <= 0 ? $cod : $due;
            $result['booked'] = $booked;
            $owed[$order->id] = bcsub($due, $booked, 2);
            if (bccomp($cod, $due, 2) === 0) {
                $result['status'] = CodReconciliationLine::MATCHED;
            } else {
                $result['status'] = CodReconciliationLine::MISMATCH;
                $result['note'] = bccomp($cod, $due, 2) < 0
                    ? 'Hãng thu ít hơn số đơn còn phải thu '.number_format((float) bcsub($due, $cod, 2), 0, ',', '.').' đ.'
                    : 'Hãng thu nhiều hơn số đơn còn phải thu '.number_format((float) bcsub($cod, $due, 2), 0, ',', '.').' đ. Chỉ ghi số còn phải thu.';
            }
        }

        return $result;
    }

    /**
     * @param  list<array{cod: string, fee: string, booked: string, status: string}>  $lines
     * @return array{cod: string, fee: string, booked: string, transferred: string, matched: int, problems: int}
     */
    private function totals(array $lines): array
    {
        $sum = fn (string $k) => array_reduce($lines, fn ($c, $l) => bcadd($c, $l[$k], 2), '0.00');
        $cod = $sum('cod');
        $fee = $sum('fee');

        return [
            'cod' => $cod,
            'fee' => $fee,
            'booked' => $sum('booked'),
            // What the carrier should have transferred for this statement.
            'transferred' => bcsub($cod, $fee, 2),
            'matched' => count(array_filter($lines, fn ($l) => $l['status'] === CodReconciliationLine::MATCHED)),
            'problems' => count(array_filter($lines, fn ($l) => in_array($l['status'], [CodReconciliationLine::MISMATCH, CodReconciliationLine::NOT_FOUND], true))),
        ];
    }

    /** The column of each field in a header row, or null when the row is not the header. @param list<string> $cells @return array{tracking: int, cod: int, fee: int|null}|null */
    private function header(array $cells): ?array
    {
        $names = array_map(fn ($c) => trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii(preg_replace('/^\xEF\xBB\xBF/', '', $c)))) ?? ''), $cells);
        $found = [];
        foreach (self::COLUMNS as $field => $aliases) {
            foreach ($aliases as $alias) { // the most specific names come first
                foreach ($names as $i => $name) {
                    if ($name !== '' && ! in_array($i, $found, true) && ($name === $alias || str_contains($name, $alias))) {
                        $found[$field] = $i;
                        break 2;
                    }
                }
            }
        }

        return isset($found['tracking'], $found['cod']) ? ['tracking' => $found['tracking'], 'cod' => $found['cod'], 'fee' => $found['fee'] ?? null] : null;
    }

    /** "1.250.000", "1,250,000", "1250000.00" or "1.250.000 đ" → "1250000.00". */
    public static function number(string $value): string
    {
        $v = preg_replace('/[^\d.,\-]/', '', $value) ?? '';
        if ($v === '' || $v === '-') {
            return '0.00';
        }
        // A separator followed by exactly three digits (and no other kind after it) groups thousands.
        if (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $v)) {
            $v = str_replace(['.', ','], '', $v);
        } else {
            $v = str_replace(',', '.', $v);
            if (substr_count($v, '.') > 1) {
                $v = str_replace('.', '', $v);
            }
        }

        return bcadd(is_numeric($v) ? $v : '0', '0', 2);
    }
}
