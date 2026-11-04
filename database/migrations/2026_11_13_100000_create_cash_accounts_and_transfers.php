<?php

use App\Modules\Cashbook\Support\DefaultCashAccounts;
use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Funds (quỹ): the cash drawer, each bank account, e-wallets, COD still held by carriers. Every money row points at
 * the fund it went into or out of, and transfers between funds are recorded once with both sides (no fake income or
 * expense).
 *
 * Existing businesses get the default funds, and every existing receipt, payment and refund is put in the fund of its
 * payment method, so fund balances start right. Only columns are added; no money row is changed otherwise.
 */
return new class extends Migration
{
    private const MONEY_TABLES = ['cash_vouchers', 'order_payments', 'supplier_payments'];

    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // cash | bank | ewallet | cod | other
            $table->string('name', 100);
            $table->string('bank_name', 50)->nullable();
            $table->string('account_number', 30)->nullable();
            $table->string('holder', 100)->nullable();
            $table->json('methods'); // payment methods that land here by default
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->timestamp('opening_at')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('from_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 15, 2)->default(0);
            $table->timestamp('transferred_at');
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'transferred_at']);
        });

        foreach (self::MONEY_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('cash_account_id')->nullable()->after('tenant_id')->constrained('cash_accounts')->restrictOnDelete();
            });
        }
        // The bank fee of a transfer is a payment voucher linked to it, so it counts as an expense like any other fee.
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->foreignId('cash_transfer_id')->nullable()->after('cash_account_id')->constrained('cash_transfers')->nullOnDelete();
        });

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $map = DefaultCashAccounts::ensure((int) $tenantId);
            foreach (self::MONEY_TABLES as $name) {
                foreach ($map as $method => $accountId) {
                    DB::table($name)->where('tenant_id', $tenantId)->where('method', $method)->whereNull('cash_account_id')->update(['cash_account_id' => $accountId]);
                }
                // Unknown methods go to "Quỹ khác". Exchange set-offs move no money and stay in no fund.
                if (isset($map['other'])) {
                    DB::table($name)->where('tenant_id', $tenantId)->whereNull('cash_account_id')->where('method', '!=', 'exchange')->update(['cash_account_id' => $map['other']]);
                }
            }
        }

        // New permission finance.accounts for the system roles.
        (new AccessSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('cash_vouchers', fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_transfer_id'));
        foreach (self::MONEY_TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_account_id'));
        }
        Schema::dropIfExists('cash_transfers');
        Schema::dropIfExists('cash_accounts');
    }
};
