<?php

use App\Modules\Access\Models\Role;
use App\Modules\Cashbook\Services\CodReconciliationService;
use Illuminate\Http\UploadedFile;

function codOrder($user, $tenant, $variant, int $price, string $tracking): string
{
    return createSalesOrder($user, $tenant, [[$variant, 1, $price]], ['is_cod' => true, 'carrier' => 'GHN', 'tracking_number' => $tracking])
        ->assertCreated()->json('data.id');
}

beforeEach(fn () => $this->travelTo(now()->setTime(5, 0)));

it('reads a carrier statement, matches parcels to COD orders and books collections and fees', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $a = codOrder($owner, $tenant, $variant, 300000, 'GHN1001');
    $b = codOrder($owner, $tenant, $variant, 250000, 'GHN1002');
    $bank = fund($owner, $tenant, 'Tài khoản ngân hàng');

    // GHN-like file: a title line, then headers with accents, amounts with thousands dots.
    $csv = "Bảng đối soát COD GHN\nMã đơn GHN,Người nhận,Tiền thu hộ (COD),Tổng phí\nGHN1001,Lan,300.000,25.000\nGHN1002,Hùng,200.000,22.000\nGHN9999,X,150.000,20.000\nGHN1001,Lan,300.000,0\n";
    $file = UploadedFile::fake()->createWithContent('doi-soat-ghn.csv', $csv);
    $preview = actingInTenant($owner, $tenant)->post('/api/v1/cod-reconciliations/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');

    expect(collect($preview['lines'])->pluck('status')->all())->toBe(['matched', 'mismatch', 'not_found', 'duplicate'])
        ->and($preview['totals'])->toMatchArray(['cod' => '950000.00', 'fee' => '67000.00', 'booked' => '500000.00', 'transferred' => '883000.00', 'matched' => 1, 'problems' => 2]);

    $lines = collect($preview['lines'])->map(fn ($l) => ['tracking' => $l['tracking'], 'cod' => $l['cod'], 'fee' => $l['fee']])->all();
    $rec = postOnce($owner, $tenant, '/api/v1/cod-reconciliations', ['carrier' => 'ghn', 'account' => $bank['id'], 'file_name' => 'doi-soat-ghn.csv', 'lines' => $lines], 'cod-1')
        ->assertCreated()->assertJsonPath('data.number', 'DS-000001')->assertJsonPath('data.matched_count', 1)
        ->assertJsonPath('data.total_booked', 500000)->assertJsonPath('data.lines.1.status', 'mismatch')->json('data');
    expect($rec['fee_voucher']['number'])->toStartWith('PC-');

    // The orders are paid by COD into the bank; the fees are one payment; the bank moved by what was booked − fees.
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$a}")->assertJsonPath('data.payment_status', 'paid');
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$b}")->assertJsonPath('data.payment_status', 'partial');
    expect(fund($owner, $tenant, 'Tài khoản ngân hàng')['balance'])->toEqual($bank['balance'] + 500000 - 67000);

    // Imported again: nothing left to book.
    $again = actingInTenant($owner, $tenant)->postJson('/api/v1/cod-reconciliations/preview', ['lines' => $lines])->json('data');
    expect(collect($again['lines'])->pluck('status')->take(1)->all())->toBe(['duplicate'])->and($again['totals']['booked'])->toBe('50000.00');

    actingInTenant($owner, $tenant)->getJson('/api/v1/cod-reconciliations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.carrier_name', 'GHN');
    actingInTenant(addMember($tenant, Role::VIEWER), $tenant)->postJson('/api/v1/cod-reconciliations/preview', ['lines' => $lines])->assertForbidden();
});

it('refuses a file without tracking and COD columns, and keeps statements per business', function () {
    [$tenant, $owner] = tenantWithOwner();
    [$other, $otherOwner] = tenantWithOwner();
    $file = UploadedFile::fake()->createWithContent('x.csv', "Tên,Số tiền\nA,1000\n");
    actingInTenant($owner, $tenant)->post('/api/v1/cod-reconciliations/preview', ['file' => $file], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 2);
    codOrder($owner, $tenant, $variant, 100000, 'JT01');
    $rec = postOnce($owner, $tenant, '/api/v1/cod-reconciliations', ['carrier' => 'jt', 'lines' => [['tracking' => 'JT01', 'cod' => 100000, 'fee' => 15000]]], 'cod-2')->assertCreated()->json('data.id');
    actingInTenant($otherOwner, $other)->getJson("/api/v1/cod-reconciliations/{$rec}")->assertNotFound();
    // Another business cannot match this business's orders.
    $preview = actingInTenant($otherOwner, $other)->postJson('/api/v1/cod-reconciliations/preview', ['lines' => [['tracking' => 'JT01', 'cod' => 100000]]])->json('data');
    expect($preview['lines'][0]['status'])->toBe('not_found');
});

it('reads Vietnamese money formats', function () {
    expect(CodReconciliationService::number('1.250.000'))->toBe('1250000.00')
        ->and(CodReconciliationService::number('1,250,000'))->toBe('1250000.00')
        ->and(CodReconciliationService::number('1250000.5'))->toBe('1250000.50')
        ->and(CodReconciliationService::number('25.000 đ'))->toBe('25000.00')
        ->and(CodReconciliationService::number(''))->toBe('0.00');
});
