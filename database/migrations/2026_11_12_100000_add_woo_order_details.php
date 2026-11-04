<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WooCommerce orders, round A: the full order (customer contact, addresses, coupons, tax, fees, transaction id),
 * the "orders since" cut-off per store and a log of every sync run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            // Orders created before this moment are never synced (set when order sync is turned on).
            $table->timestamp('orders_since')->nullable()->after('connected_at');
        });
        DB::table('marketplace_accounts')->where('marketplace', 'woocommerce')->whereNull('orders_since')
            ->update(['orders_since' => DB::raw('COALESCE(connected_at, created_at)')]);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('external_number', 40)->nullable()->after('external_order_id');
            $table->text('buyer_email')->nullable()->after('buyer_phone');
            $table->text('addresses')->nullable()->after('shipping_address'); // encrypted json: billing + shipping
            $table->string('external_customer_id', 40)->nullable()->after('customer_id');
            $table->decimal('tax_total', 15, 2)->default(0)->after('discount');
            $table->decimal('fee_total', 15, 2)->default(0)->after('tax_total');
            $table->json('coupon_codes')->nullable()->after('fee_total');
            $table->string('transaction_id', 100)->nullable()->after('payment_method');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('tax', 15, 2)->default(0)->after('discount');
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 20); // orders
            $table->string('trigger', 20); // schedule | user
            $table->string('status', 20); // running | ok | partial | failed
            $table->timestamp('window_from')->nullable();
            $table->timestamp('window_to')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->json('counts')->nullable(); // created, updated, unchanged, skipped, stale, failed
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'marketplace_account_id', 'started_at'], 'sync_runs_tenant_account_started_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('tax'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['external_number', 'buyer_email', 'addresses', 'external_customer_id', 'tax_total', 'fee_total', 'coupon_codes', 'transaction_id']));
        Schema::table('marketplace_accounts', fn (Blueprint $table) => $table->dropColumn('orders_since'));
    }
};
