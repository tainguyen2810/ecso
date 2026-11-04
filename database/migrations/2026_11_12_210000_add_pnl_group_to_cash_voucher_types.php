<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Which profit line a voucher type feeds: sales (direct selling costs, in contribution profit), operating (running
 * the business) or other. Adds the "Tiền sàn chuyển về" (marketplace payout, not income: its revenue is already
 * counted on the orders) and "Phí ngân hàng" types for businesses that already have their types.
 */
return new class extends Migration
{
    private const SALES = ['Chi phí quảng cáo', 'Phí vận chuyển', 'Vật tư đóng gói'];

    private const OPERATING = ['Lương nhân viên', 'Thuê mặt bằng', 'Điện, nước, internet', 'Phần mềm, dịch vụ', 'Thuế, phí', 'Chi phí khác', 'Phí ngân hàng'];

    public function up(): void
    {
        Schema::table('cash_voucher_types', function (Blueprint $table) {
            $table->string('pnl_group', 12)->nullable()->after('counts_in_pnl'); // sales | operating | other (null)
        });

        DB::table('cash_voucher_types')->where('direction', 'payment')->whereIn('name', self::SALES)->update(['pnl_group' => 'sales']);
        DB::table('cash_voucher_types')->where('direction', 'payment')->whereIn('name', self::OPERATING)->update(['pnl_group' => 'operating']);

        $now = now();
        foreach (DB::table('cash_voucher_types')->distinct()->pluck('tenant_id') as $tenantId) {
            foreach ([['receipt', 'Tiền sàn chuyển về', false, null], ['payment', 'Phí ngân hàng', true, 'operating']] as [$direction, $name, $pnl, $group]) {
                $exists = DB::table('cash_voucher_types')->where(['tenant_id' => $tenantId, 'direction' => $direction, 'name' => $name])->exists();
                if (! $exists) {
                    DB::table('cash_voucher_types')->insert([
                        'ulid' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'direction' => $direction, 'name' => $name,
                        'counts_in_pnl' => $pnl, 'pnl_group' => $group, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('cash_voucher_types', fn (Blueprint $table) => $table->dropColumn('pnl_group'));
    }
};
