<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COD reconciliations (đối soát COD): a carrier's statement (GHN, Viettel Post, SPX, J&T…) imported from its file.
 * Each line is a parcel: its tracking number, the COD the carrier collected and its fee. Confirming books, for each
 * own-channel COD order found, the money collected as an order payment into the fund the carrier paid into, and the
 * carrier's fees as one payment voucher, so the fund moves by what the carrier actually transferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('carrier', 20);
            $table->string('file_name', 255)->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $table->timestamp('remitted_at');
            $table->decimal('total_cod', 15, 2)->default(0);
            $table->decimal('total_fee', 15, 2)->default(0);
            $table->decimal('total_booked', 15, 2)->default(0);
            $table->unsignedInteger('lines_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->foreignId('fee_voucher_id')->nullable()->constrained('cash_vouchers')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'remitted_at']);
        });

        Schema::create('cod_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cod_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->string('tracking_number', 100);
            $table->decimal('cod_amount', 15, 2)->default(0);
            $table->decimal('fee', 15, 2)->default(0);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_payment_id')->nullable()->constrained('order_payments')->nullOnDelete();
            // matched | mismatch | not_found | duplicate | no_cod
            $table->string('status', 20);
            $table->decimal('booked', 15, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'tracking_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_reconciliation_lines');
        Schema::dropIfExists('cod_reconciliations');
    }
};
