<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling an order and voiding a payment get their own permissions (orders.cancel, orders.payment.void), so an
 * owner can take them away from staff who only sell. Nobody loses access: every role that could do it before (the
 * old orders.update / orders.payment) is given the new permission, including roles a business made itself.
 */
return new class extends Migration
{
    private const SPLIT = ['orders.cancel' => 'orders.update', 'orders.payment.void' => 'orders.payment'];

    public function up(): void
    {
        (new AccessSeeder)->run();

        $ids = DB::table('permissions')->pluck('id', 'key');
        foreach (self::SPLIT as $new => $old) {
            if (! isset($ids[$new], $ids[$old])) {
                continue;
            }
            $roles = DB::table('role_permissions')->where('permission_id', $ids[$old])->pluck('role_id');
            $have = DB::table('role_permissions')->where('permission_id', $ids[$new])->pluck('role_id')->all();
            $rows = $roles->diff($have)->map(fn ($role) => ['role_id' => $role, 'permission_id' => $ids[$new]])->values()->all();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::SPLIT))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
