<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval of large payment vouchers, and voids of vouchers in a closed period booked as a reversal on the day
 * of the void.
 *
 * - status: completed (counts), pending (waiting for approval: shown but counts nowhere), rejected (also voided).
 * - void_reversed: the voucher was voided after its day was closed. It still counts on its own day, and the
 *   void counts as the opposite movement on the day it was made, so the closed period does not change.
 *
 * New permissions: finance.approve (owners and admins), finance.balances split from finance.view so nobody
 * loses what they see today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->string('status', 10)->default('completed')->after('direction');
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->boolean('void_reversed')->default(false)->after('void_reason');
            $table->index(['tenant_id', 'status']);
        });

        (new AccessSeeder)->run();
        $ids = DB::table('permissions')->pluck('id', 'key');
        if (isset($ids['finance.balances'], $ids['finance.view'])) {
            $roles = DB::table('role_permissions')->where('permission_id', $ids['finance.view'])->pluck('role_id');
            $have = DB::table('role_permissions')->where('permission_id', $ids['finance.balances'])->pluck('role_id')->all();
            $rows = $roles->diff($have)->map(fn ($role) => ['role_id' => $role, 'permission_id' => $ids['finance.balances']])->values()->all();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'approved_at', 'void_reversed']);
        });
        $ids = DB::table('permissions')->whereIn('key', ['finance.approve', 'finance.balances'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
