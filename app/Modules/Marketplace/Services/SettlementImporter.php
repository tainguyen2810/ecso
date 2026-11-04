<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceSettlement;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads a settlement (đối soát) report exported from a marketplace's seller centre: one row per order with what the
 * buyer paid, each fee and the payout. Columns are recognised by header names: ECSO's own template, plus the names
 * the seller centres are expected to use. Those marketplace names are NOT verified against real files yet; a file
 * whose headers are not recognised is refused with the list of columns found, never guessed.
 *
 * Fees are stored as positive amounts. Re-importing the same order replaces its row.
 */
class SettlementImporter
{
    /** key => headers that mean it (lower case). The first one is ECSO's template header. */
    public const COLUMNS = [
        'external_order_id' => ['mã đơn hàng', 'mã đơn', 'order id', 'order/adjustment id', 'order number', 'order_id', 'số đơn hàng'],
        'order_amount' => ['giá trị hàng', 'tổng tiền hàng', 'giá sản phẩm', 'giá gốc', 'product price', 'original price', 'subtotal', 'order amount', 'item price', 'total revenue'],
        'commission_fee' => ['phí hoa hồng', 'hoa hồng', 'commission fee', 'commission', 'referral fee', 'phí cố định'],
        'payment_fee' => ['phí thanh toán', 'payment fee', 'transaction fee', 'phí giao dịch', 'payment processing fee'],
        'service_fee' => ['phí dịch vụ', 'service fee', 'phí chương trình', 'program fee'],
        'fixed_fee' => ['phí cố định mỗi đơn', 'fixed fee per order', 'phí xử lý đơn'],
        'shipping_fee' => ['phí vận chuyển sàn trừ', 'phí vận chuyển', 'shipping fee', 'shipping fee paid by seller', 'phí ship'],
        'net_amount' => ['thực nhận', 'số tiền thực nhận', 'tổng tiền đã thanh toán', 'tiền thực nhận', 'settlement amount', 'total settlement amount', 'payout amount'],
        'settled_at' => ['ngày đối soát', 'ngày thanh toán', 'ngày hoàn thành thanh toán', 'settlement date', 'payout date', 'settled time'],
    ];

    public const MAX_ROWS = 20000;

    /**
     * @return array{rows: list<array<string, mixed>>, columns: array<string, string>, errors: list<string>}
     */
    public function read(string $path, string $extension): array
    {
        $reader = strtolower($extension) === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($path);
        $synonyms = [];
        foreach (self::COLUMNS as $key => $names) {
            foreach ($names as $name) {
                $synonyms[$name] ??= $key;
            }
        }

        $map = null;
        $found = [];
        $rows = [];
        $line = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $line++;
                $cells = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v : trim((string) $v), $row->toArray());

                if ($map === null) {
                    // Reports often start with a title block: the header is the first row naming an order id column.
                    $labels = array_map(fn ($v) => is_string($v) ? mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF|\s+/u', ' ', $v) ?? '')) : '', $cells);
                    $candidate = [];
                    foreach ($labels as $i => $label) {
                        if (isset($synonyms[$label]) && ! in_array($synonyms[$label], $candidate, true)) {
                            $candidate[$i] = $synonyms[$label];
                        }
                    }
                    if (in_array('external_order_id', $candidate, true)) {
                        $map = $candidate;
                        $found = array_filter($labels);
                    } elseif ($line >= 30) {
                        break 2;
                    }

                    continue;
                }

                $record = [];
                foreach ($map as $i => $key) {
                    $record[$key] = $cells[$i] ?? null;
                }
                if (blank($record['external_order_id'] ?? null)) {
                    continue;
                }
                $rows[] = $record;
                if (count($rows) > self::MAX_ROWS) {
                    $reader->close();

                    return ['rows' => [], 'columns' => [], 'errors' => ['File quá '.number_format(self::MAX_ROWS).' dòng, hãy chia nhỏ theo tháng.']];
                }
            }
            break; // first sheet only
        }
        $reader->close();

        if ($map === null) {
            return ['rows' => [], 'columns' => [], 'errors' => ['Không tìm thấy cột "Mã đơn hàng". Hãy dùng file mẫu của ECSO hoặc gửi file này để ECSO hỗ trợ.']];
        }
        $keys = array_values($map);
        $errors = [];
        if (! in_array('order_amount', $keys, true)) {
            $errors[] = 'Thiếu cột giá trị hàng (ví dụ "Giá trị hàng", "Tổng tiền hàng").';
        }
        if (! array_intersect(['commission_fee', 'payment_fee', 'service_fee', 'fixed_fee'], $keys)) {
            $errors[] = 'Không thấy cột phí nào (hoa hồng, thanh toán, dịch vụ).';
        }

        return ['rows' => $errors ? [] : $rows, 'columns' => array_combine(array_map(fn ($i) => $found[$i] ?? (string) $i, array_keys($map)), $keys), 'errors' => $errors];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{imported: int, linked: int, batch: string}
     */
    public function import(MarketplaceAccount $account, array $rows): array
    {
        $batch = (string) Str::ulid();
        $linked = 0;

        DB::transaction(function () use ($account, $rows, $batch, &$linked) {
            $orders = Order::query()->where('marketplace_account_id', $account->id)
                ->whereIn('external_order_id', array_map(fn ($r) => (string) $r['external_order_id'], $rows))->pluck('id', 'external_order_id');

            foreach ($rows as $r) {
                $orderId = (string) $r['external_order_id'];
                $amounts = [];
                foreach (['order_amount', 'commission_fee', 'payment_fee', 'service_fee', 'fixed_fee', 'shipping_fee'] as $k) {
                    $amounts[$k] = abs(self::number($r[$k] ?? null));
                }
                $net = array_key_exists('net_amount', $r) && $r['net_amount'] !== '' && $r['net_amount'] !== null
                    ? self::number($r['net_amount'])
                    : $amounts['order_amount'] - $amounts['commission_fee'] - $amounts['payment_fee'] - $amounts['service_fee'] - $amounts['fixed_fee'] - $amounts['shipping_fee'];

                MarketplaceSettlement::query()->updateOrCreate(
                    ['marketplace_account_id' => $account->id, 'external_order_id' => $orderId],
                    $amounts + [
                        'net_amount' => $net,
                        'order_id' => $orders[$orderId] ?? null,
                        'settled_at' => self::date($r['settled_at'] ?? null) ?? now(),
                        'source' => 'file',
                        'import_batch' => $batch,
                    ],
                );
                $linked += (int) isset($orders[$orderId]);
            }
        });

        return ['imported' => count($rows), 'linked' => $linked, 'batch' => $batch];
    }

    /** "1.234.567", "1,234,567", "-12.500 ₫", "1234567.5", "1.234,5" → float */
    public static function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $s = preg_replace('/[^\d,.\-]/', '', (string) $value) ?? '';
        if ($s === '' || $s === '-') {
            return 0.0;
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {        // 1.234.567,5
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {  // 1,234,567.5
            $s = str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }

        return (float) $s;
    }

    private static function date(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $d = \DateTime::createFromFormat('!'.$format, $s);
            if ($d !== false) {
                return Carbon::instance($d);
            }
        }

        return null;
    }
}
