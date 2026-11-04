<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closing the books (khóa sổ) up to a day, with the fund balances at that day kept as a snapshot, and cash counts
 * (kiểm quỹ) that compare a counted amount with the balance on record and book the difference as a voucher.
 *
 * Two new permissions split from existing ones, so nobody gains or loses anything: finance.vouchers.void from
 * finance.vouchers, finance.close from finance.accounts.
 */
return new class extends Migration
{
    private const SPLIT = ['finance.vouchers.void' => 'finance.vouchers', 'finance.close' => 'finance.accounts'];

    public function up(): void
    {
        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('closed_until');
            $table->json('balances'); // account id => balance at the end of closed_until
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reopen_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'reopened_at']);
        });

        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->timestamp('counted_at');
            $table->decimal('system_balance', 15, 2);
            $table->decimal('counted_amount', 15, 2);
            $table->decimal('difference', 15, 2);
            $table->foreignId('cash_voucher_id')->nullable()->constrained('cash_vouchers')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'cash_account_id', 'counted_at']);
        });

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
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('cash_closings');
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::SPLIT))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
