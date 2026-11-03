<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt and payment vouchers (PT-000001 / PC-000001) for money that is not an order payment or a supplier
 * payment: rent, ads, salaries, other income. Each has a type the business manages; the type says whether it
 * counts in profit and loss. Vouchers are voided, never edited or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_voucher_types', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10); // receipt | payment
            $table->string('name', 100);
            $table->boolean('counts_in_pnl')->default(true);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'direction', 'name']);
        });

        Schema::create('cash_vouchers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->string('direction', 10); // receipt | payment
            $table->foreignId('cash_voucher_type_id')->constrained()->restrictOnDelete();
            $table->string('party_kind', 20); // customer | supplier | staff | carrier | other
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('party_name', 255);
            $table->string('method', 20);
            $table->decimal('amount', 15, 2);
            $table->boolean('counts_in_pnl')->default(true);
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'paid_at']);
            $table->index(['tenant_id', 'direction', 'paid_at']);
        });

        // New permission finance.vouchers for existing tenants' system roles.
        (new AccessSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_vouchers');
        Schema::dropIfExists('cash_voucher_types');
    }
};
