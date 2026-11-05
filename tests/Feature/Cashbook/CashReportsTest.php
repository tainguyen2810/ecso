<?php

use App\Modules\Access\Models\Role;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(fn () => $this->travelTo(now()->setTime(5, 0)));

it('adds up money in and out by kind, month by month, without transfers', function () {
    [$tenant, $owner] = tenantWithOwner();
    $rent = cashType($owner, $tenant, 'payment', 'Thuê mặt bằng');
    $other = cashType($owner, $tenant, 'receipt', 'Thu nhập khác');
    $bank = fund($owner, $tenant, 'Tài khoản ngân hàng');
    $cash = fund($owner, $tenant, 'Tiền mặt');
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(2)->toIso8601String();

    postOnce($owner, $tenant, '/api/v1/cash-vouchers', ['direction' => 'payment', 'type' => $rent, 'party_kind' => 'other', 'party_name' => 'Chủ nhà', 'method' => 'cash', 'amount' => 5000000, 'paid_at' => $lastMonth], 'r1')->assertCreated();
    postOnce($owner, $tenant, '/api/v1/cash-vouchers', ['direction' => 'payment', 'type' => $rent, 'party_kind' => 'other', 'party_name' => 'Chủ nhà', 'method' => 'cash', 'amount' => 5000000], 'r2')->assertCreated();
    postOnce($owner, $tenant, '/api/v1/cash-vouchers', ['direction' => 'receipt', 'type' => $other, 'party_kind' => 'other', 'party_name' => 'Chủ nhà', 'method' => 'cash', 'amount' => 200000], 'r3')->assertCreated();
    postOnce($owner, $tenant, '/api/v1/cash-transfers', ['from' => $cash['id'], 'to' => $bank['id'], 'amount' => 100000], 'r4')->assertCreated();

    $from = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
    $to = now()->toDateString();
    $data = actingInTenant($owner, $tenant)->getJson("/api/v1/reports/cash-by-type?from={$from}&to={$to}")->assertOk()->json('data');
    [$prev, $this_] = $data['months'];
    $rentRow = collect($data['rows'])->firstWhere('label', 'Thuê mặt bằng');
    expect($rentRow)->toMatchArray(['direction' => 'out', 'entries' => 2, 'total' => -10000000, "m{$prev}" => -5000000, "m{$this_}" => -5000000])
        ->and(collect($data['rows'])->firstWhere('label', 'Thu nhập khác')['total'])->toEqual(200000)
        ->and($data['totals'])->toMatchArray(['in' => 200000, 'out' => 10000000, 'total' => -9800000]);

    $months = actingInTenant($owner, $tenant)->getJson("/api/v1/reports/cash-by-type?by=month&from={$from}&to={$to}")->json('data');
    expect($months['rows'][1])->toMatchArray(['label' => $this_, 'in' => 200000, 'out' => 5000000, 'net' => -4800000]);

    actingInTenant(addMember($tenant, Role::WAREHOUSE_STAFF), $tenant)->getJson('/api/v1/reports/cash-by-type')->assertForbidden();
});

it('exports the whole cash book as an Excel file, balances only for members who may see them', function () {
    [$tenant, $owner] = tenantWithOwner();
    $rent = cashType($owner, $tenant, 'payment', 'Chi phí khác');
    postOnce($owner, $tenant, '/api/v1/cash-vouchers', ['direction' => 'payment', 'type' => $rent, 'party_kind' => 'other', 'party_name' => '=1+1', 'method' => 'cash', 'amount' => 300000], 'e1')->assertCreated();

    $res = actingInTenant($owner, $tenant)->get('/api/v1/reports/cashbook/export?from='.now()->toDateString().'&to='.now()->toDateString());
    $res->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'sq').'.xlsx';
    file_put_contents($path, $res->streamedContent());
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    expect($rows[1])->toContain('Tồn quỹ')
        ->and(collect($rows)->first(fn ($r) => in_array('=1+1', $r, true)))->not->toBeNull()
        ->and(end($rows)[7])->toEqual(300000);
    $xml = shell_exec('unzip -p '.escapeshellarg($path).' xl/worksheets/sheet1.xml');
    expect($xml)->not->toContain('<f>');

    $accountant = addMember($tenant, Role::ORDER_STAFF);
    actingInTenant($accountant, $tenant)->get('/api/v1/reports/cashbook/export')->assertForbidden();
});
