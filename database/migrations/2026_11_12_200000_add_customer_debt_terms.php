<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer debt beyond orders: opening balances, adjustments with a reason and receipts of those balances
 * (customer_debt_entries), plus payment terms (days until an order is overdue) and a credit limit, on the customer
 * or as a default on its group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_debt_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16); // opening | adjustment | receipt
            // Signed: positive adds to what the customer owes, negative takes off.
            $table->decimal('amount', 15, 2);
            $table->date('entry_date');
            $table->date('due_date')->nullable();
            $table->string('reason', 500);
            // A receipt of an opening balance or adjustment is also a cash book voucher.
            $table->foreignId('cash_voucher_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'customer_id', 'entry_date']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('status');
            $table->decimal('credit_limit', 15, 2)->nullable()->after('payment_terms_days');
        });
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('payment_method');
            $table->decimal('credit_limit', 15, 2)->nullable()->after('payment_terms_days');
        });

        (new AccessSeeder)->run();
        // System roles follow PermissionRegistry. A business's own roles that could delete customers can also adjust
        // their debt (the most sensitive customer right so far).
        $from = DB::table('permissions')->where('key', 'customers.delete')->value('id');
        $to = DB::table('permissions')->where('key', 'customers.debt_adjust')->value('id');
        if ($from && $to) {
            $roles = DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->whereNotNull('roles.tenant_id')->where('permission_id', $from)->pluck('role_id');
            foreach ($roles as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $to]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'customers.debt_adjust')->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        Schema::table('customer_groups', fn (Blueprint $table) => $table->dropColumn(['payment_terms_days', 'credit_limit']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['payment_terms_days', 'credit_limit']));
        Schema::dropIfExists('customer_debt_entries');
    }
};
