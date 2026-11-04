<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WooCommerce round 3: marketplace prices from fees. A fee table per shop (optionally per category), the fees each
 * marketplace actually charged per order (settlement data), and the alerts of the daily fee check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_price_rules', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            // null = the whole shop; a category rule overrides only the fields it sets (null = as the shop)
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('commission_pct', 5, 2)->nullable();
            $table->decimal('payment_pct', 5, 2)->nullable();
            $table->decimal('service_pct', 5, 2)->nullable();
            $table->decimal('fixed_fee', 15, 2)->nullable();
            $table->unsignedInteger('rounding')->default(100);
            $table->decimal('min_margin_pct', 5, 2)->default(8);
            $table->boolean('auto_apply')->default(false);
            // Shop rule only: let the daily fee check rewrite the percentages from the actual fees.
            $table->boolean('auto_update_fees')->default(false);
            // {"days":30,"orders":214,"at":"..."} when the percentages were taken from settlement data
            $table->json('suggested_from')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_account_id', 'category_id'], 'price_rules_account_category_unique');
        });

        Schema::create('marketplace_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_id', 64);
            $table->timestamp('settled_at')->nullable();
            // Money the buyer paid for the goods (before the shop's own vouchers), and each fee as a positive amount.
            $table->decimal('order_amount', 15, 2)->default(0);
            $table->decimal('commission_fee', 15, 2)->default(0);
            $table->decimal('payment_fee', 15, 2)->default(0);
            $table->decimal('service_fee', 15, 2)->default(0);
            $table->decimal('fixed_fee', 15, 2)->default(0);
            $table->decimal('shipping_fee', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->string('source', 10)->default('file'); // file | api
            $table->char('import_batch', 26)->nullable();
            $table->timestamps();
            $table->unique(['marketplace_account_id', 'external_order_id'], 'settlements_account_order_unique');
            $table->index(['tenant_id', 'marketplace_account_id', 'settled_at'], 'settlements_tenant_account_settled_index');
        });

        Schema::create('fee_alerts', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20); // fee_drift | loss_orders
            $table->string('fingerprint', 120);
            $table->json('data');
            $table->string('status', 12)->default('open'); // open | dismissed | applied
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'fingerprint']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_alerts');
        Schema::dropIfExists('marketplace_settlements');
        Schema::dropIfExists('channel_price_rules');
    }
};
