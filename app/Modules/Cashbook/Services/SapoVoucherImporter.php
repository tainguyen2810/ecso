<?php

namespace App\Modules\Cashbook\Services;

use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Models\CashVoucherType;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Chuyển dữ liệu" › Sổ quỹ: the receipt and payment vouchers ("phiếu thu", "phiếu chi") the shop wrote by hand in
 * Sapo GO, read by the "Lấy sổ quỹ Sapo" bookmark (resources/js/apps/tenant/pages/settings/sapoGoVouchersExport.ts).
 *
 * Vouchers Sapo made by itself for an order, a purchase order or a return are left out: those payments came over with
 * the orders (Đơn hàng cũ) and the purchase orders (Nhà cung cấp và nhập hàng), and taking them again would count
 * them twice. The rest (rent, salaries, shipping fees, packaging…) are kept as history (imported_from = 'sapo'): they
 * hold no fund here, since the money moved in Sapo's funds, so fund balances leave them out (CashFlows); they are
 * listed in Thu chi and count in the profit report and the expense book. Their type keeps Sapo's name (an existing
 * type of the same name is used). Sending the file again skips vouchers already brought over.
 */
class SapoVoucherImporter
{
    public const FORMAT = 'ezsell-sapo-go-vouchers';

    public const SOURCE = 'sapo';

    /** Codes of the documents Sapo makes vouchers for by itself: orders, purchase orders and their returns. */
    private const AUTO_REFERENCE = '/^(SON|PON|SRN|PRN|RON|RTN|SPN|POR)\d/i';

    /** @var array<string, CashVoucherType> */
    private array $types = [];

    /**
     * @return array{vouchers: list<array<string, mixed>>, skipped_auto: int, store: string|null}|null
     */
    public function read(string $path): ?array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['vouchers'] ?? null)) {
            return null;
        }

        return [
            'vouchers' => array_values(array_filter($data['vouchers'], 'is_array')),
            'skipped_auto' => (int) ($data['skipped_auto'] ?? 0),
            'store' => isset($data['store']) ? (string) $data['store'] : null,
        ];
    }

    /**
     * @param  array{vouchers: list<array<string, mixed>>, skipped_auto: int}  $file
     * @return array<string, mixed>
     */
    public function import(array $file, bool $dryRun): array
    {
        $summary = [
            'total' => count($file['vouchers']), 'created' => 0, 'existing' => 0, 'skipped_auto' => $file['skipped_auto'], 'cancelled' => 0,
            'receipts' => 0, 'payments' => 0, 'in' => '0', 'out' => '0', 'from' => null, 'to' => null, 'types' => [], 'errors' => [],
        ];
        $codes = array_values(array_filter(array_map(fn ($v) => $this->code($v), $file['vouchers'])));
        $existing = CashVoucher::query()->whereIn('number', $codes)->pluck('imported_from', 'number')->all();

        $run = function () use ($file, $dryRun, $existing, &$summary) {
            foreach ($file['vouchers'] as $index => $data) {
                $code = $this->code($data);
                if ($code === '') {
                    $summary['errors'][] = ['row' => $index + 1, 'message' => 'Phiếu không có mã.'];

                    continue;
                }
                if ($this->automatic($data)) {
                    $summary['skipped_auto']++;

                    continue;
                }
                if (in_array(mb_strtolower((string) ($data['status'] ?? '')), ['cancelled', 'canceled', 'deleted', 'voided'], true)) {
                    $summary['cancelled']++;

                    continue;
                }
                if (array_key_exists($code, $existing)) {
                    if ($existing[$code] === self::SOURCE) {
                        $summary['existing']++;
                    } else {
                        $summary['errors'][] = ['row' => $index + 1, 'message' => "Mã {$code} đã dùng cho một phiếu tạo trong EzChat Bán hàng: bỏ qua."];
                    }

                    continue;
                }
                $amount = $this->money($data['amount'] ?? 0);
                if (bccomp($amount, '0', 2) <= 0) {
                    continue;
                }
                $direction = $this->direction($data);
                $at = $this->date($data['paid_on'] ?? null) ?? $this->date($data['created_on'] ?? null) ?? now();
                $summary['from'] = $summary['from'] === null || $at->lt($summary['from']) ? $at : $summary['from'];
                $summary['to'] = $summary['to'] === null || $at->gt($summary['to']) ? $at : $summary['to'];
                $type = $this->typeName($data, $direction);
                $key = ($direction === CashVoucher::RECEIPT ? 'Thu: ' : 'Chi: ').$type;
                $summary['types'][$key] = bcadd($summary['types'][$key] ?? '0', $amount, 2);
                if ($direction === CashVoucher::RECEIPT) {
                    $summary['receipts']++;
                    $summary['in'] = bcadd($summary['in'], $amount, 2);
                } else {
                    $summary['payments']++;
                    $summary['out'] = bcadd($summary['out'], $amount, 2);
                }
                $summary['created']++;
                if (! $dryRun) {
                    $this->create($data, $code, $direction, $type, $amount, $at);
                }
            }
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        arsort($summary['types'], SORT_NUMERIC);
        $summary['types'] = array_map(fn ($name, $amount) => ['name' => $name, 'amount' => $amount], array_keys($summary['types']), $summary['types']);
        $summary['from'] = $summary['from']?->toDateString();
        $summary['to'] = $summary['to']?->toDateString();

        return $summary;
    }

    private function create(array $data, string $code, string $direction, string $typeName, string $amount, Carbon $at): void
    {
        $type = $this->type($typeName, $direction, $data['counts_in_pnl'] ?? null);
        [$kind, $customer, $supplier] = $this->party($data);
        $voucher = new CashVoucher;
        // No fund: the money moved in Sapo's funds.
        $voucher->forceFill([
            'number' => $code,
            'direction' => $direction,
            'cash_voucher_type_id' => $type->id,
            'party_kind' => $kind,
            'customer_id' => $customer?->id,
            'supplier_id' => $supplier?->id,
            'party_name' => $this->text($data['party_name'] ?? null, 255) ?? $customer?->name ?? $supplier?->name ?? 'Không rõ',
            'method' => $this->method($data['method'] ?? null),
            'amount' => $amount,
            'counts_in_pnl' => is_bool($data['counts_in_pnl'] ?? null) ? $data['counts_in_pnl'] : $type->counts_in_pnl,
            'reference' => $this->text($data['reference'] ?? null, 100),
            'note' => $this->text($data['note'] ?? null, 500),
            'paid_at' => $at,
            'status' => CashVoucher::COMPLETED,
            'imported_from' => self::SOURCE,
            'created_at' => $this->date($data['created_on'] ?? null) ?? $at,
            'updated_at' => now(),
        ])->save();
    }

    /** Made by Sapo for an order, a purchase order or a return (their payments came over with them). */
    private function automatic(array $data): bool
    {
        if (($data['auto'] ?? false) === true) {
            return true;
        }
        $type = mb_strtolower(trim((string) ($data['type'] ?? '')));
        if (in_array($type, ['tự động', 'tu dong', 'auto', 'automatic'], true)) {
            return true;
        }

        return (bool) preg_match(self::AUTO_REFERENCE, trim((string) ($data['ref_code'] ?? '')));
    }

    private function direction(array $data): string
    {
        $value = mb_strtolower((string) ($data['direction'] ?? ''));

        return in_array($value, ['receipt', 'thu', 'in'], true) ? CashVoucher::RECEIPT : CashVoucher::PAYMENT;
    }

    private function typeName(array $data, string $direction): string
    {
        $name = trim((string) ($data['type'] ?? ''));
        if ($name === '') {
            return $direction === CashVoucher::RECEIPT ? 'Thu khác' : 'Chi phí khác';
        }
        // Sapo writes some type names in capitals ("PHÍ VẬN CHUYỂN"): keep them readable.
        if ($name === mb_strtoupper($name)) {
            $name = mb_strtoupper(mb_substr($name, 0, 1)).mb_strtolower(mb_substr($name, 1));
        }

        return mb_substr($name, 0, 100);
    }

    /** The shop's type of that name (any case), made when missing. */
    private function type(string $name, string $direction, mixed $pnl): CashVoucherType
    {
        $key = $direction.':'.mb_strtolower($name);
        if (isset($this->types[$key])) {
            return $this->types[$key];
        }
        $type = CashVoucherType::query()->where('direction', $direction)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($type === null) {
            $type = new CashVoucherType;
            $type->forceFill([
                'direction' => $direction,
                'name' => $name,
                'counts_in_pnl' => is_bool($pnl) ? $pnl : true,
                'description' => 'Chuyển từ Sapo',
                'is_active' => true,
            ])->save();
        }

        return $this->types[$key] = $type;
    }

    /** @return array{0: string, 1: Customer|null, 2: Supplier|null} */
    private function party(array $data): array
    {
        $kind = mb_strtolower(trim((string) ($data['party_kind'] ?? '')));
        $code = trim((string) ($data['party_code'] ?? ''));
        $name = trim((string) ($data['party_name'] ?? ''));
        $find = function (string $model) use ($code, $name) {
            return ($code !== '' ? $model::query()->where('code', $code)->first() : null)
                ?? ($name !== '' ? $model::query()->where('name', $name)->first() : null);
        };

        return match (true) {
            str_contains($kind, 'supplier') || str_contains($kind, 'cung cấp') => ['supplier', null, $find(Supplier::class)],
            str_contains($kind, 'customer') || str_contains($kind, 'khách') => ['customer', $find(Customer::class), null],
            str_contains($kind, 'employee') || str_contains($kind, 'staff') || str_contains($kind, 'nhân viên') => ['staff', null, null],
            str_contains($kind, 'delivery') || str_contains($kind, 'carrier') || str_contains($kind, 'vận chuyển') => ['carrier', null, null],
            default => ['other', null, null],
        };
    }

    private function method(mixed $name): string
    {
        $name = mb_strtolower(trim((string) $name));

        return match (true) {
            in_array($name, CashVoucher::METHODS, true) => $name,
            str_contains($name, 'mặt') || str_contains($name, 'cash') => 'cash',
            str_contains($name, 'chuyển') || str_contains($name, 'bank') || str_contains($name, 'transfer') || str_contains($name, 'ngân hàng') => 'bank_transfer',
            str_contains($name, 'thẻ') || str_contains($name, 'card') || str_contains($name, 'pos') => 'card',
            str_contains($name, 'ví') || str_contains($name, 'momo') || str_contains($name, 'wallet') => 'ewallet',
            str_contains($name, 'cod') => 'cod',
            default => 'other',
        };
    }

    private function code(array $data): string
    {
        return mb_substr(trim((string) ($data['code'] ?? '')), 0, 32);
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function money(mixed $value): string
    {
        return bcadd(is_numeric($value) ? (string) round((float) $value, 2) : '0', '0', 2);
    }
}
