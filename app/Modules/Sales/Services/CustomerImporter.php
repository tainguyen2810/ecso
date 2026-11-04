<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Support\PhoneNumber;
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
 *  - All rows are validated first; if any row is invalid nothing is written (all-or-nothing).
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

    public const GENDERS = ['nam' => 'male', 'nữ' => 'female', 'nu' => 'female', 'khác' => 'other', 'khac' => 'other', 'male' => 'male', 'female' => 'female', 'other' => 'other'];

    public const STATUSES = ['đang giao dịch' => 'active', 'ngừng giao dịch' => 'inactive', 'active' => 'active', 'inactive' => 'inactive'];

    public const MAX_ROWS = 5000;

    public function __construct(private readonly CustomerService $customers) {}

    /**
     * @return array{rows: array<int, array<string, string>>, errors: list<array{row: int, message: string}>}
     */
    public function read(string $path, string $extension): array
    {
        $reader = strtolower($extension) === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);

        $rows = [];
        $errors = [];
        $header = null;
        $line = 0;
        $byHeader = array_flip(array_map(fn ($h) => mb_strtolower($h), self::COLUMNS));

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $line++;
                $cells = array_map(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? $v->format('d/m/Y') : $v)), $row->toArray());

                if ($header === null) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0] ?? '');
                    // A column is recognised by its Vietnamese header or by its key (code, name, phone…).
                    $header = array_map(fn ($h) => $byHeader[mb_strtolower($h)] ?? (array_key_exists(mb_strtolower($h), self::COLUMNS) ? mb_strtolower($h) : null), $cells);
                    if (! in_array('name', $header, true)) {
                        $errors[] = ['row' => 1, 'message' => 'Thiếu cột bắt buộc: '.self::COLUMNS['name']];
                        break 2;
                    }

                    continue;
                }

                if (implode('', $cells) === '') {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    $errors[] = ['row' => $line, 'message' => 'Tệp vượt quá '.self::MAX_ROWS.' dòng.'];
                    break 2;
                }

                $assoc = array_fill_keys(array_keys(self::COLUMNS), '');
                foreach ($header as $index => $key) {
                    if ($key !== null) {
                        $assoc[$key] = $cells[$index] ?? '';
                    }
                }
                $rows[$line] = $assoc;
            }
            break; // first sheet only
        }

        $reader->close();

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param  array<int, array<string, string>>  $rows  keyed by file line number
     * @return array{created: int, updated: int, errors: list<array{row: int, message: string}>, dry_run: bool}
     */
    public function import(array $rows, bool $dryRun = false): array
    {
        $byCode = Customer::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtolower($code) => $id]);
        $byPhone = Customer::query()->whereNotNull('phone_hash')->pluck('id', 'phone_hash');
        $groups = CustomerGroup::query()->get(['id', 'ulid', 'code', 'name']);
        $groupIndex = $groups->flatMap(fn ($g) => [mb_strtolower($g->code) => $g->ulid, mb_strtolower($g->name) => $g->ulid]);

        $errors = [];
        $plans = [];
        $codesInFile = [];
        $phonesInFile = [];
        $matched = [];
        $add = function (int $line, string $message) use (&$errors) {
            $errors[] = ['row' => $line, 'message' => $message];
        };

        foreach ($rows as $line => $row) {
            $data = [];
            $code = $row['code'];
            $phone = PhoneNumber::normalize($row['phone'] !== '' ? $row['phone'] : null);
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
                ($g = $groupIndex[mb_strtolower($row['group'])] ?? null) ? $data['group_id'] = $g : $add($line, "Chưa có nhóm khách hàng \"{$row['group']}\".");
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

            $plans[$line] = ['id' => $id, 'data' => $data];
        }

        $created = count(array_filter($plans, fn ($p) => $p['id'] === null));
        $updated = count($plans) - $created;
        if ($errors || $dryRun) {
            return ['created' => $errors ? 0 : $created, 'updated' => $errors ? 0 : $updated, 'errors' => $errors, 'dry_run' => $dryRun];
        }

        DB::transaction(function () use ($plans) {
            $existing = Customer::query()->whereIn('id', array_filter(array_column($plans, 'id')))->get()->keyBy('id');
            foreach ($plans as $plan) {
                $plan['id'] === null
                    ? $this->customers->create($plan['data'] + ['source' => 'import'])
                    : $this->customers->update($existing[$plan['id']], $plan['data']);
            }
        });

        return ['created' => $created, 'updated' => $updated, 'errors' => [], 'dry_run' => false];
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
