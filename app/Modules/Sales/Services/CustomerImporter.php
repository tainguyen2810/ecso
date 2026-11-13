<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerDebtEntry;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Support\PhoneNumber;
use App\Shared\Support\ImportHeaders;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Imports customers from CSV/XLSX with Vietnamese headers (the same ones the export writes).
 *
 * Matching rules (no guessing):
 *  - A row whose customer code exists updates that customer; otherwise a row whose phone exists updates the owner of
 *    that phone; otherwise a new customer is created. Customers are never matched by name.
 *  - On an update, empty cells leave the field as it is.
 *  - All rows are validated first; if any row is invalid nothing is written (all-or-nothing), unless skip_invalid
 *    is asked: then those rows are left out and reported.
 *  - Files exported from Sapo or KiotViet are read as they are (see ALIASES). Their opening debt column becomes a
 *    "Nợ đầu kỳ" document for a customer without one, when the user may adjust debts.
 */
class CustomerImporter
{
    /** key => header in the file */
    public const COLUMNS = [
        'code' => 'Mã khách hàng',
        'name' => 'Tên khách hàng',
        'phone' => 'Số điện thoại',
        'email' => 'Email',
        'address' => 'Địa chỉ',
        'group' => 'Nhóm khách hàng',
        'birthday' => 'Ngày sinh',
        'gender' => 'Giới tính',
        'tax_code' => 'Mã số thuế',
        'tags' => 'Tags',
        'status' => 'Trạng thái',
        'note' => 'Ghi chú',
    ];

    /** key => header patterns (normalized by ImportHeaders): EzSell's, Sapo's and KiotViet's names. */
    public const ALIASES = [
        'code' => ['/^ma_khach_hang$/', '/^ma_kh$/', '/^code$/'],
        'name' => ['/^ten_khach_hang$/', '/^ten_kh$/', '/^name$/'],
        'phone' => ['/^so_dien_thoai$/', '/^dien_thoai$/', '/^di_dong$/', '/^sdt$/', '/^phone$/'],
        'email' => ['/^email$/'],
        'address' => ['/^dia_chi$/', '/^address$/'],
        'group' => ['/^nhom_khach_hang$/', '/^nhom_kh$/', '/^group$/'],
        'birthday' => ['/^ngay_sinh$/', '/^birthday$/'],
        'gender' => ['/^gioi_tinh$/', '/^gender$/'],
        'tax_code' => ['/^ma_so_thue$/', '/^mst$/', '/^tax_code$/'],
        'tags' => ['/^tags$/', '/^the$/'],
        'status' => ['/^trang_thai$/', '/^status$/'],
        'note' => ['/^ghi_chu$/', '/^note$/'],
        'debt' => ['/^no_can_thu_hien_tai$/', '/^no_hien_tai$/', '/^cong_no$/', '/^du_no$/', '/^no_dau_ky$/', '/^debt$/'],
    ];

    public const GENDERS = ['nam' => 'male', 'nữ' => 'female', 'nu' => 'female', 'khác' => 'other', 'khac' => 'other', 'male' => 'male', 'female' => 'female', 'other' => 'other'];

    public const STATUSES = ['đang giao dịch' => 'active', 'ngừng giao dịch' => 'inactive', 'đang hoạt động' => 'active', 'ngừng hoạt động' => 'inactive', 'active' => 'active', 'inactive' => 'inactive', '1' => 'active', '0' => 'inactive'];

    /** Rows imported at once, while the user waits (config ecso.customer_import.sync_rows); a larger file is checked and imported in the background. */
    public const MAX_ROWS = 5000;

    /** Rows of one file imported in the background (config ecso.customer_import.background_rows). */
    public const BACKGROUND_MAX_ROWS = 30000;

    public static function syncRows(): int
    {
        return (int) config('ecso.customer_import.sync_rows', self::MAX_ROWS);
    }

    public static function backgroundRows(): int
    {
        return (int) config('ecso.customer_import.background_rows', self::BACKGROUND_MAX_ROWS);
    }

    public function __construct(private readonly CustomerService $customers, private readonly CustomerDebtEntries $debts) {}

    /**
     * Reads at most $max rows; `more` tells that the file has more (no error is added for it, the caller decides).
     *
     * @return array{rows: array<int, array<string, string>>, errors: list<array{row: int, message: string}>, more: bool, source: string, columns: list<array{header: string, key: string|null}>}
     */
    public function read(string $path, string $extension, int $max = self::MAX_ROWS): array
    {
        $reader = strtolower($extension) === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);

        $rows = [];
        $errors = [];
        $more = false;
        $header = null;
        $source = ImportHeaders::EZSELL;
        $columns = [];
        $line = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $line++;
                $cells = array_map(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? $v->format('d/m/Y') : $v)), $row->toArray());

                if ($header === null) {
                    $cells[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $cells[0] ?? '');
                    // A column is recognised by its Vietnamese header, its key (code, name, phone…) or the name Sapo/KiotViet give it.
                    $source = ImportHeaders::source($cells);
                    $header = array_flip(ImportHeaders::map($cells, self::ALIASES));
                    foreach ($cells as $index => $title) {
                        if ($title !== '') {
                            $columns[] = ['header' => $title, 'key' => $header[$index] ?? null];
                        }
                    }
                    if (! in_array('name', $header, true)) {
                        $errors[] = ['row' => 1, 'message' => 'Thiếu cột bắt buộc: '.self::COLUMNS['name']];
                        break 2;
                    }

                    continue;
                }

                if (implode('', $cells) === '') {
                    continue;
                }
                if (count($rows) >= $max) {
                    $more = true;
                    break 2;
                }

                $assoc = array_fill_keys([...array_keys(self::COLUMNS), 'debt'], '');
                foreach ($header as $index => $key) {
                    $assoc[$key] = $cells[$index] ?? '';
                }
                $rows[$line] = $assoc;
            }
            break; // first sheet only
        }

        $reader->close();

        return ['rows' => $rows, 'errors' => $errors, 'more' => $more, 'source' => $source, 'columns' => $columns];
    }

    /**
     * @param  array<int, array<string, string>>  $rows  keyed by file line number
     * @param  (callable(int): void)|null  $progress  told how many rows are written so far, every 100 rows
     * @param  bool  $skipInvalid  leave out the rows with errors (reported in `skipped`) instead of importing nothing
     * @param  bool  $withDebt  bring the opening debt column over (the user may adjust customer debts)
     * @return array{created: int, updated: int, errors: list<array{row: int, message: string}>, dry_run: bool, skipped: list<array{row: int, message: string}>, warnings: list<array{row: int, message: string}>, debts: int}
     */
    public function import(array $rows, bool $dryRun = false, ?callable $progress = null, bool $skipInvalid = false, bool $withDebt = false, string $source = ImportHeaders::EZSELL): array
    {
        $byCode = Customer::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtolower($code) => $id]);
        $byPhone = Customer::query()->whereNotNull('phone_hash')->pluck('id', 'phone_hash');
        $groups = CustomerGroup::query()->get(['id', 'ulid', 'code', 'name']);
        $groupIndex = $groups->flatMap(fn ($g) => [mb_strtolower($g->code) => $g->ulid, mb_strtolower($g->name) => $g->ulid]);
        $hasOpening = CustomerDebtEntry::query()->where('kind', CustomerDebtEntry::OPENING)->whereNull('voided_at')->pluck('customer_id')->flip()->all();

        $plan = fn (array $rows) => $this->plan($rows, $byCode, $byPhone, $groupIndex, $hasOpening, $withDebt, $source);
        [$plans, $errors, $warnings] = $plan($rows);
        // Leaving rows out can change what is left (a phone no longer repeats): checked again until clean.
        $skipped = [];
        while ($skipInvalid && $errors !== []) {
            foreach ($errors as $error) {
                unset($rows[$error['row']]);
                $skipped[] = $error;
            }
            [$plans, $errors, $warnings] = $plan($rows);
        }

        $created = count(array_filter($plans, fn ($p) => $p['id'] === null));
        $updated = count($plans) - $created;
        $debts = count(array_filter($plans, fn ($p) => $p['debt'] !== null));
        $result = ['skipped' => $skipped, 'warnings' => $warnings];
        if ($errors || $dryRun) {
            return ['created' => $errors ? 0 : $created, 'updated' => $errors ? 0 : $updated, 'errors' => $errors, 'dry_run' => $dryRun, 'debts' => $errors ? 0 : $debts] + $result;
        }

        DB::transaction(function () use ($plans, $progress) {
            $existing = Customer::query()->whereIn('id', array_filter(array_column($plans, 'id')))->get()->keyBy('id');
            $done = 0;
            foreach ($plans as $plan) {
                $customer = $plan['id'] === null
                    ? $this->customers->create($plan['data'] + ['source' => 'import'])
                    : $this->customers->update($existing[$plan['id']], $plan['data']);
                if ($plan['debt'] !== null) {
                    $this->debts->record($customer, ['kind' => CustomerDebtEntry::OPENING, 'amount' => $plan['debt'], 'reason' => 'Nợ đầu kỳ chuyển từ tệp nhập khách hàng']);
                }
                if ($progress !== null && ++$done % 100 === 0) {
                    $progress($done);
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'errors' => [], 'dry_run' => false, 'debts' => $debts] + $result;
    }

    /**
     * What each row would do, and what is wrong with the rows.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, int>  $hasOpening  customer id => any, for customers that already have an opening debt
     * @return array{0: array<int, array{id: int|null, data: array<string, mixed>, debt: string|null}>, 1: list<array{row: int, message: string}>, 2: list<array{row: int, message: string}>}
     */
    private function plan(array $rows, $byCode, $byPhone, $groupIndex, array $hasOpening, bool $withDebt, string $source): array
    {
        $errors = [];
        $warnings = [];
        $plans = [];
        $codesInFile = [];
        $phonesInFile = [];
        $matched = [];
        $add = function (int $line, string $message) use (&$errors) {
            $errors[] = ['row' => $line, 'message' => $message];
        };
        $warn = function (int $line, string $message) use (&$warnings) {
            $warnings[] = ['row' => $line, 'message' => $message];
        };

        foreach ($rows as $line => $row) {
            $data = [];
            $code = $row['code'];
            // KiotViet keeps several numbers in one cell ("0912…, 0987…"): the first is the customer's phone.
            $rawPhone = trim((string) (preg_split('/[,;\/]/', $row['phone'])[0] ?? ''));
            $phone = PhoneNumber::normalize($rawPhone !== '' ? $rawPhone : null);
            $hash = PhoneNumber::hash($phone);

            if ($code !== '') {
                if (mb_strlen($code) > 32 || ! preg_match('/^[A-Za-z0-9._-]+$/', $code)) {
                    $add($line, "Mã khách \"{$code}\" không hợp lệ.");
                } elseif (isset($codesInFile[mb_strtolower($code)])) {
                    $add($line, "Mã khách {$code} trùng với dòng {$codesInFile[mb_strtolower($code)]}.");
                } else {
                    $codesInFile[mb_strtolower($code)] = $line;
                }
            }
            if ($phone !== null) {
                if (! PhoneNumber::isValid($phone)) {
                    $add($line, "Số điện thoại \"{$row['phone']}\" không hợp lệ.");
                } elseif (isset($phonesInFile[$hash])) {
                    $add($line, "Số điện thoại trùng với dòng {$phonesInFile[$hash]}.");
                } else {
                    $phonesInFile[$hash] = $line;
                    $data['phone'] = $phone;
                }
            }

            $id = ($code !== '' ? $byCode[mb_strtolower($code)] ?? null : null) ?? ($hash ? $byPhone[$hash] ?? null : null);
            if ($id !== null) {
                if (isset($matched[$id])) {
                    $add($line, "Dòng này và dòng {$matched[$id]} cùng trỏ tới một khách.");
                }
                $matched[$id] = $line;
                $owner = $hash ? $byPhone[$hash] ?? null : null;
                if ($owner !== null && $owner !== $id) {
                    $add($line, 'Số điện thoại đã thuộc một khách khác.');
                }
            } elseif ($code !== '') {
                $data['code'] = $code;
            }

            if ($row['name'] === '' && $id === null) {
                $add($line, 'Thiếu tên khách hàng.');
            } elseif (mb_strlen($row['name']) > 150) {
                $add($line, 'Tên khách hàng tối đa 150 ký tự.');
            } elseif ($row['name'] !== '') {
                $data['name'] = $row['name'];
            }

            if ($row['email'] !== '') {
                filter_var($row['email'], FILTER_VALIDATE_EMAIL) && mb_strlen($row['email']) <= 150
                    ? $data['email'] = $row['email'] : $add($line, "Email \"{$row['email']}\" không hợp lệ.");
            }
            if ($row['address'] !== '') {
                mb_strlen($row['address']) <= 500 ? $data['address'] = $row['address'] : $add($line, 'Địa chỉ tối đa 500 ký tự.');
            }
            if ($row['group'] !== '') {
                $g = $groupIndex[mb_strtolower($row['group'])] ?? null;
                if ($g !== null) {
                    $data['group_id'] = $g;
                } elseif ($source !== ImportHeaders::EZSELL) {
                    // A file from another app names that app's groups: the customer is brought over without one.
                    $warn($line, "Bỏ qua nhóm \"{$row['group']}\" (chưa có trong EzSell).");
                } else {
                    $add($line, "Chưa có nhóm khách hàng \"{$row['group']}\".");
                }
            }
            if ($row['birthday'] !== '') {
                ($d = $this->date($row['birthday'])) ? $data['birthday'] = $d : $add($line, "Ngày sinh \"{$row['birthday']}\" không hợp lệ (dd/mm/yyyy).");
            }
            if ($row['gender'] !== '') {
                ($g = self::GENDERS[mb_strtolower($row['gender'])] ?? null) ? $data['gender'] = $g : $add($line, 'Giới tính ghi Nam, Nữ hoặc Khác.');
            }
            if ($row['tax_code'] !== '') {
                mb_strlen($row['tax_code']) <= 20 ? $data['tax_code'] = $row['tax_code'] : $add($line, 'Mã số thuế tối đa 20 ký tự.');
            }
            if ($row['tags'] !== '') {
                $data['tags'] = $this->customers->tags(preg_split('/[,;]/', $row['tags']) ?: []);
            }
            if ($row['status'] !== '') {
                ($s = self::STATUSES[mb_strtolower($row['status'])] ?? null) ? $data['status'] = $s : $add($line, 'Trạng thái ghi "Đang giao dịch" hoặc "Ngừng giao dịch".');
            }
            if ($row['note'] !== '') {
                mb_strlen($row['note']) <= 1000 ? $data['note'] = $row['note'] : $add($line, 'Ghi chú tối đa 1000 ký tự.');
            }

            $debt = null;
            if (($row['debt'] ?? '') !== '') {
                $amount = ImportHeaders::number($row['debt']);
                if (! is_numeric($amount) || abs((float) $amount) > 9999999999) {
                    $add($line, "Công nợ \"{$row['debt']}\" không phải là số.");
                } elseif ((float) $amount < 0) {
                    $warn($line, 'Bỏ qua công nợ âm (cửa hàng nợ khách): ghi điều chỉnh công nợ sau khi nhập.');
                } elseif ((float) $amount > 0) {
                    if (! $withDebt) {
                        $warn($line, 'Bỏ qua công nợ: bạn không có quyền điều chỉnh công nợ.');
                    } elseif ($id !== null && isset($hasOpening[$id])) {
                        $warn($line, 'Bỏ qua công nợ: khách đã có nợ đầu kỳ.');
                    } else {
                        $debt = bcadd($amount, '0', 2);
                    }
                }
            }

            $plans[$line] = ['id' => $id, 'data' => $data, 'debt' => $debt];
        }

        return [$plans, $errors, $warnings];
    }

    private function date(string $value): ?string
    {
        foreach (['d/m/Y', 'j/n/Y', 'Y-m-d', 'd-m-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }
            if ($date && $date->format($format) === $value && $date->isPast() && $date->year >= 1900) {
                return $date->toDateString();
            }
        }

        return null;
    }
}
