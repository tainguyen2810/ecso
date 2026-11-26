<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bán thuê bao (dịch vụ theo kỳ): hosting, bảo trì, phần mềm năm…
 *  - products.type gains "service" (no stock, sold once) and "subscription" (no stock, has a period); products.subscription
 *    keeps the renewal rules (grace days, auto renewal order, reminders);
 *  - product_variants.period_months: the cycle each SKU of a subscription sells (1, 6, 12 months…);
 *  - order_items.service_variant_id: a no-stock SKU on an own-channel order (the line keeps no product_variant_id so
 *    the stock engine never touches it), order_items.subscription: the start date, label and subscription it renews;
 *  - customer_subscriptions: one service a customer uses, its dates follow its periods (one per order line);
 *  - customer_subscription_events: what happened (reminded, renewal order, paused…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('subscription')->nullable()->after('type');
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedSmallInteger('period_months')->nullable()->after('options');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('service_variant_id')->nullable()->after('product_variant_id')->constrained('product_variants')->nullOnDelete();
            $table->json('subscription')->nullable()->after('mapping_status');
        });

        Schema::create('customer_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 150)->nullable();
            $table->string('status', 12)->default('active'); // active | paused | cancelled
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('remind')->default(true);
            $table->json('reminded')->nullable();
            $table->foreignId('renewal_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'ends_on']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('customer_subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('months');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('status', 10); // pending | active | void
            $table->timestamps();
            $table->unique(['order_id', 'line_no']);
            $table->index(['customer_subscription_id', 'status'], 'sub_periods_sub_status_index');
        });

        Schema::create('customer_subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['customer_subscription_id', 'id'], 'sub_events_sub_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_subscription_events');
        Schema::dropIfExists('customer_subscription_periods');
        Schema::dropIfExists('customer_subscriptions');
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_variant_id');
            $table->dropColumn('subscription');
        });
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('period_months'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('subscription'));
    }
};
