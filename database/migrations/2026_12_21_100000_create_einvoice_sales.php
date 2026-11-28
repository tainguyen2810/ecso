<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling e-invoice packages as a reseller ("Mua gói hóa đơn"): the packages the platform offers (set in /admin),
 * each shop's order with its registration steps, and requests for providers we do not connect yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('einvoice_packages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('provider', 20);
            $table->string('name', 80);
            $table->unsignedInteger('invoices');
            $table->decimal('price', 15, 2);
            $table->decimal('setup_fee', 15, 2)->default(0);
            $table->decimal('signing_price', 15, 2)->nullable(); // remote signing for a year; null = not offered
            $table->string('note', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('featured')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('einvoice_orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('einvoice_packages')->nullOnDelete();
            $table->string('number', 20)->unique();
            $table->string('provider', 20);
            $table->string('package_name', 80);
            $table->unsignedInteger('invoices');
            $table->decimal('price', 15, 2);
            $table->decimal('setup_fee', 15, 2)->default(0);
            $table->boolean('signing')->default(false);
            $table->decimal('signing_price', 15, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->string('transfer_code', 20)->unique();
            $table->string('status', 20); // awaiting_payment | paid | account_created | waiting_signature | tax_approved | connected | cancelled
            $table->json('company');
            $table->string('shop_note', 500)->nullable();
            $table->string('sign_url', 500)->nullable();
            $table->json('steps')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_ref', 100)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('einvoice_provider_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 60);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoice_provider_requests');
        Schema::dropIfExists('einvoice_orders');
        Schema::dropIfExists('einvoice_packages');
    }
};
