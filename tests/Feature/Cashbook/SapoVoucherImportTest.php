<?php

use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Models\CashVoucherType;
use Illuminate\Http\UploadedFile;

/** Cấu hình › Chuyển dữ liệu › Sổ quỹ: the file saved by the "Lấy sổ quỹ Sapo" bookmark. */
function sapoVouchersFile(array $vouchers, int $skippedAuto = 0): UploadedFile
{
    return UploadedFile::fake()->createWithContent('sapo-so-quy-cuahang.json', json_encode([
        'format' => 'ezsell-sapo-go-vouchers', 'version' => 1, 'store' => 'cuahang.mysapogo.com', 'skipped_auto' => $skippedAuto, 'vouchers' => $vouchers,
    ]));
}

function sapoVoucher(string $code, array $overrides = []): array
{
    $at = now()->subDays(3)->toIso8601String();

    return array_replace([
        'id' => crc32($code), 'code' => $code, 'direction' => 'payment', 'status' => 'completed', 'type' => 'Thuê mặt bằng', 'auto' => false, 'ref_code' => null,
        'party_kind' => 'other', 'party_name' => 'Chủ nhà', 'party_code' => null, 'amount' => 5000000, 'method' => 'Chuyển khoản',
        'paid_on' => $at, 'created_on' => $at, 'note' => 'Tiền nhà tháng trước', 'counts_in_pnl' => null,
    ], $overrides);
}

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = actingInTenant($this->owner, $this->tenant);
});

it('brings hand-written vouchers over as history: in Thu chi and profit, not in any fund', function () {
    $funds = fn () => collect(actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/cash-accounts')->json('data'))->pluck('balance', 'name')->all();
    $before = $funds();
    $vouchers = [
        sapoVoucher('PVN00001'),
        sapoVoucher('PVN00002', ['type' => 'PHÍ VẬN CHUYỂN', 'party_kind' => 'Đối tác vận chuyển', 'party_name' => 'Be Bike', 'amount' => 34000, 'method' => 'Tiền mặt']),
        sapoVoucher('RVN00003', ['direction' => 'receipt', 'type' => 'Thu nhập khác', 'amount' => 200000]),
        // Sapo's own voucher for a purchase order: its payment came over with the purchase orders.
        sapoVoucher('PVN00004', ['type' => 'Tự động', 'ref_code' => 'PON01943', 'amount' => 10240000]),
        sapoVoucher('PVN00005', ['status' => 'cancelled']),
    ];

    $check = $this->api->postJson('/api/v1/cash-vouchers/import/sapo', ['file' => sapoVouchersFile($vouchers, 120), 'dry_run' => true])->assertOk();
    expect($check->json('created'))->toBe(3)
        ->and($check->json('skipped_auto'))->toBe(121)
        ->and($check->json('cancelled'))->toBe(1)
        ->and((float) $check->json('out'))->toBe(5034000.0)
        ->and((float) $check->json('in'))->toBe(200000.0)
        ->and(CashVoucher::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);

    $this->api->postJson('/api/v1/cash-vouchers/import/sapo', ['file' => sapoVouchersFile($vouchers)])->assertOk()->assertJsonPath('created', 3);

    $shipping = CashVoucher::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('number', 'PVN00002')->sole();
    expect($shipping->imported_from)->toBe('sapo')
        ->and($shipping->party_kind)->toBe('carrier')
        ->and($shipping->method)->toBe('cash')
        ->and($shipping->cash_account_id)->toBeNull()
        // An existing type of the same name is used, whatever the case.
        ->and(CashVoucherType::withoutGlobalScopes()->find($shipping->cash_voucher_type_id)->name)->toBe('Phí vận chuyển');

    // Listed in Thu chi, but no fund moved.
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/cash-vouchers?direction=payment')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.imported', true)->assertJsonPath('data.0.created_by', 'Chuyển từ Sapo');
    expect($funds())->toEqual($before);

    // They count in the profit report.
    $pl = collect(actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/reports/profit-loss')->assertOk()->json('data.lines'))->keyBy('key');
    expect($pl['other_income']['current'])->toEqual(200000)
        ->and($pl['selling_expenses']['current'] + $pl['operating_expenses']['current'] + $pl['misc_expenses']['current'])->toEqual(5034000);

    // Sending it again makes nothing new; they cannot be voided here.
    $this->api->postJson('/api/v1/cash-vouchers/import/sapo', ['file' => sapoVouchersFile($vouchers)])->assertOk()->assertJsonPath('created', 0)->assertJsonPath('existing', 3);
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/cash-vouchers/{$shipping->ulid}/void", ['reason' => 'x'])->assertUnprocessable();
});

it('refuses a file that is not from the bookmark and staff without the right', function () {
    $this->api->postJson('/api/v1/cash-vouchers/import/sapo', ['file' => UploadedFile::fake()->createWithContent('x.json', '{"format":"other"}')])->assertUnprocessable();

    $agent = addMember($this->tenant, 'WAREHOUSE_STAFF');
    actingInTenant($agent, $this->tenant)->postJson('/api/v1/cash-vouchers/import/sapo', ['file' => sapoVouchersFile([sapoVoucher('PVN1')])])->assertForbidden();
});
