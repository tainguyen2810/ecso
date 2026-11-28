<?php

use App\Modules\Access\Models\Role;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Support\PayrollRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(now('Asia/Ho_Chi_Minh')->setDate(2026, 11, 20)->setTime(10, 0));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->cashier = addMember($this->tenant, Role::ORDER_STAFF);
    $this->tech = addMember($this->tenant, Role::ORDER_STAFF);
    $this->shirt = makeVariant($this->tenant, 'AO-1', ['ref_price' => 200000]);
    $this->repair = makeVariant($this->tenant, 'LAP-1', ['ref_price' => 300000]);
    stockIn($this->tenant, $this->shirt, 50);
    stockIn($this->tenant, $this->repair, 50);
    $this->service = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/categories', ['name' => 'Dịch vụ lắp đặt'])->assertCreated()->json('data.id');
    Product::withoutGlobalScopes()->whereKey($this->repair->product_id)->update(['category_id' => DB::table('categories')->where('ulid', $this->service)->value('id')]);
});

function local(string $at)
{
    return now()->parse($at, 'Asia/Ho_Chi_Minh')->utc();
}

it('counts attendance from cash shifts, phone check-ins and days a manager sets', function () {
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/staff/members/'.$this->cashier->ulid.'/profile', ['pay_type' => 'month', 'base_salary' => 6500000, 'shift_start' => '08:00', 'shift_end' => '17:00', 'title' => 'Thu ngân'])->assertOk();

    // A cash shift opened 08:25 and closed 17:05 on 18/11: 8.5 hours, late.
    DB::table('cash_shifts')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'number' => 'CA-9', 'user_id' => $this->cashier->id, 'warehouse_id' => defaultWarehouse($this->tenant)->id,
        'status' => 'closed', 'opened_at' => local('2026-11-18 08:25'), 'closed_at' => local('2026-11-18 17:05'), 'opening_cash' => 0, 'created_at' => now(), 'updated_at' => now()]);
    // The technician checks in on the phone today.
    actingInTenant($this->tech, $this->tenant)->postJson('/api/v1/staff/attendance/check', ['action' => 'in', 'lat' => 10.77, 'lng' => 106.69])->assertOk()->assertJsonPath('data.check_in', '10:00');
    actingInTenant($this->tech, $this->tenant)->postJson('/api/v1/staff/attendance/check', ['action' => 'in'])->assertUnprocessable();
    $this->travel(8)->hours();
    actingInTenant($this->tech, $this->tenant)->postJson('/api/v1/staff/attendance/check', ['action' => 'out'])->assertOk()->assertJsonPath('data.check_out', '18:00');
    // Paid leave on 19/11 for the cashier, and an excused late arrival set by hand.
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/staff/attendance/'.$this->cashier->ulid.'/2026-11-19', ['status' => 'leave'])->assertOk();
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/staff/attendance/'.$this->cashier->ulid.'/2026-11-17', ['status' => 'work', 'check_in' => '08:30', 'check_out' => '17:00', 'excuse_late' => true])->assertOk();

    $data = actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/staff/attendance?month=2026-11')->assertOk()->json('data');
    $cashier = collect($data['members'])->firstWhere('id', $this->cashier->ulid);
    expect($cashier['days']['18'])->toMatchArray(['status' => 'late', 'hours' => 8.5, 'late' => 25, 'in' => '08:25', 'out' => '17:05', 'source' => 'shift'])
        ->and($cashier['days']['19']['status'])->toBe('leave')
        ->and($cashier['days']['17'])->toMatchArray(['status' => 'work', 'late' => 0, 'hours' => 8.5])
        ->and($cashier['work_days'])->toBe(3)->and($cashier['late'])->toBe(1)->and($cashier['title'])->toBe('Thu ngân');
    $tech = collect($data['members'])->firstWhere('id', $this->tech->ulid);
    expect($tech['days']['20'])->toMatchArray(['hours' => 8.0, 'source' => 'phone', 'located' => true]);

    // A member sees only their own row and cannot set days.
    $mine = actingInTenant($this->tech, $this->tenant)->getJson('/api/v1/staff/attendance?month=2026-11')->assertOk()->json('data');
    expect($mine['members'])->toHaveCount(1)->and($mine['manage'])->toBeFalse();
    actingInTenant($this->tech, $this->tenant)->putJson('/api/v1/staff/attendance/'.$this->tech->ulid.'/2026-11-19', ['status' => 'leave'])->assertForbidden();
});

it('pays commission from own sales, per unit of a category and a store bonus, returns given back', function () {
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/staff/commission-rules', ['name' => 'Bán tại quầy', 'kind' => 'counter', 'rate' => 1, 'user_ids' => [$this->cashier->ulid]])->assertCreated();
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/staff/commission-rules', ['name' => 'Bán online', 'kind' => 'online', 'rate' => 2])->assertCreated();
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/staff/commission-rules', ['name' => 'Lắp đặt', 'kind' => 'category_unit', 'amount' => 50000, 'category_id' => $this->service, 'user_ids' => [$this->tech->ulid]])->assertCreated();
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/staff/commission-rules', ['name' => 'Thưởng vượt mức', 'kind' => 'store_bonus', 'rate' => 0.5, 'threshold' => 1000000])->assertCreated();
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/staff/commission-rules', ['name' => 'Thiếu danh mục', 'kind' => 'category_unit', 'amount' => 1])->assertUnprocessable();

    $sale = function ($user, array $lines, bool $counter, string $day) {
        $id = createSalesOrder($user, $this->tenant, $lines)->assertCreated()->json('data.id');
        $order = Order::withoutGlobalScopes()->where('ulid', $id)->sole();
        $order->forceFill(['status' => 'DELIVERED', 'delivered_at' => local("{$day} 09:00"), 'cash_shift_id' => $counter ? 999 : null])->save();

        return $order;
    };
    // cash_shift_id only marks a counter sale here; no shift row is needed for the figures.
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    $sale($this->cashier, [[$this->shirt, 5, 200000]], true, '2026-11-05');   // 1.000.000 counter
    $returned = $sale($this->cashier, [[$this->shirt, 1, 200000]], true, '2026-11-06'); // 200.000 counter, returned
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    $sale($this->tech, [[$this->repair, 3, 300000]], false, '2026-11-07');   // 900.000 online, 3 installations
    $rid = DB::table('order_returns')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'order_id' => $returned->id, 'number' => 'TH-1', 'type' => 'customer_return', 'status' => 'approved', 'created_at' => local('2026-11-08 10:00'), 'updated_at' => now()]);
    DB::table('order_return_lines')->insert(['tenant_id' => $this->tenant->id, 'order_return_id' => $rid, 'order_item_id' => DB::table('order_items')->where('order_id', $returned->id)->value('id'), 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $data = actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/staff/commissions?month=2026-11')->assertOk()->json('data');
    expect($data['rules'])->toHaveCount(4)->and((float) $data['store_revenue'])->toBe(1900000.0);
    expect((float) $data['store_counter'])->toBe(1000000.0)->and((float) $data['store_online'])->toBe(900000.0);
    // By week: sales of 5–7/11 in week 1, the return of 8/11 comes off week 2.
    expect(collect($data['weeks'])->pluck('amount')->map(fn ($v) => (float) $v)->all())->toBe([2100000.0, -200000.0, 0.0, 0.0])
        ->and($data['weeks'][3]['to'])->toBe('2026-11-30');
    $cashier = collect($data['members'])->firstWhere('id', $this->cashier->ulid);
    // 1% × 1.000.000 + store bonus 0.5% × 1.000.000 (store 1.9M > 1M)
    expect((float) $cashier['sales'])->toBe(1000000.0)->and((float) $cashier['commission'])->toBe(15000.0);
    $tech = collect($data['members'])->firstWhere('id', $this->tech->ulid);
    // 2% × 900.000 + 3 × 50.000 + 0.5% × 900.000
    expect((float) $tech['commission'])->toBe(18000.0 + 150000.0 + 4500.0);

    // A member sees their own figures only, without the rules.
    $mine = actingInTenant($this->tech, $this->tenant)->getJson('/api/v1/staff/commissions?month=2026-11')->assertOk()->json('data');
    expect($mine['members'])->toHaveCount(1)->and($mine['rules'])->toBe([])->and($mine['weeks'])->toBe([]);
});

it('makes the payroll: pay by days, commission, advance, insurance, approve, pay into the cash book', function () {
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/staff/members/'.$this->tech->ulid.'/profile', ['pay_type' => 'month', 'base_salary' => 7000000, 'standard_days' => 26, 'allowance' => 300000, 'insured_salary' => 7000000, 'dependents' => 0])->assertOk();
    foreach (range(1, 20) as $d) {
        if (now()->setDate(2026, 11, $d)->isSunday()) {
            continue;
        }
        actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/staff/attendance/'.$this->tech->ulid.'/2026-11-'.str_pad((string) $d, 2, '0', STR_PAD_LEFT), ['status' => 'work', 'check_in' => '08:00', 'check_out' => '17:00'])->assertOk();
    }
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/staff/advances', ['member' => $this->tech->ulid, 'amount' => 1000000, 'method' => 'cash'])->assertCreated();
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/staff/payroll/2026-11/adjust/'.$this->tech->ulid, ['bonus' => 200000, 'note' => 'Khách khen'])->assertOk();

    $data = actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/staff/payroll?month=2026-11')->assertOk()->json('data');
    $line = collect($data['lines'])->firstWhere('id', $this->tech->ulid);
    $base = round(7000000 * 17 / 26);
    expect($line['days'])->toBe(17)->and((float) $line['base_pay'])->toBe($base)
        ->and((float) $line['advance'])->toBe(1000000.0)->and((float) $line['insurance'])->toBe(735000.0)->and((float) $line['employer_insurance'])->toBe(1505000.0)
        ->and((float) $line['pit'])->toBe(0.0)
        ->and((float) $line['net'])->toBe($base + 300000 + 200000 - 1000000 - 735000);
    expect(collect($data['lines'])->pluck('id'))->not->toContain($this->cashier->ulid); // no pay profile, no commission
    expect($data['advances'])->toHaveCount(1)->and($data['advances'][0])->toMatchArray(['member' => $this->tech->ulid, 'method' => 'cash', 'amount' => 1000000.0]);

    // Not visible to the member until approved; then only their own line.
    expect(actingInTenant($this->tech, $this->tenant)->getJson('/api/v1/staff/payroll?month=2026-11')->json('data.lines'))->toBe([]);
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/staff/payroll/2026-11/approve')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', $this->owner->name);
    expect(actingInTenant($this->tech, $this->tenant)->getJson('/api/v1/staff/payroll?month=2026-11')->json('data.advances'))->toBe([]);
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/staff/payroll/2026-11/adjust/'.$this->tech->ulid, ['bonus' => 1])->assertUnprocessable();
    expect(actingInTenant($this->tech, $this->tenant)->getJson('/api/v1/staff/payroll?month=2026-11')->json('data.lines'))->toHaveCount(1);
    actingInTenant($this->tech, $this->tenant)->postJson('/api/v1/staff/payroll/2026-11/pay', ['method' => 'cash'])->assertForbidden();

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/staff/payroll/2026-11/pay', ['method' => 'bank_transfer'])->assertOk()->assertJsonPath('data.status', 'paid');
    $voucher = DB::table('cash_vouchers')->where('tenant_id', $this->tenant->id)->where('type_name', 'Lương nhân viên')->sole();
    expect((int) $voucher->staff_id)->toBe($this->tech->id)->and((float) $voucher->amount)->toBe((float) $line['net'])->and($voucher->reference)->toBe('Lương 2026-11');
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/staff/payroll/2026-11/pay', ['method' => 'cash'])->assertUnprocessable();
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/staff/payroll/2026-11/reopen')->assertUnprocessable();
});

it('taxes salary on the progressive brackets', function () {
    expect(PayrollRules::pit(0))->toBe(0.0)
        ->and(PayrollRules::pit(5000000))->toBe(250000.0)
        ->and(PayrollRules::pit(12000000))->toBe(250000.0 + 500000.0 + 300000.0);
});
