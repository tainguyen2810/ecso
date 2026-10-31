<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->restrictOnDelete();
            $table->string('marketplace', 20);
            $table->string('external_order_id', 64);
            $table->string('raw_status', 64);
            $table->string('status', 32);
            $table->string('payment_method', 50)->nullable();
            $table->boolean('is_cod')->default(false);
            $table->string('currency', 3)->default('VND');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('shipping_fee', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            // Personal data: encrypted at rest; the phone is stored masked only.
            $table->text('buyer_name')->nullable();
            $table->string('buyer_phone_masked', 20)->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('shipping_carrier', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->string('buyer_note', 500)->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('ship_by_at')->nullable();
            $table->timestamp('external_updated_at', 6)->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('has_shortage')->default(false);
            $table->boolean('has_unmapped')->default(false);
            $table->string('sync_warning', 500)->nullable();
            $table->timestamp('packing_slip_printed_at')->nullable();
            $table->json('raw')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['marketplace_account_id', 'external_order_id'], 'orders_account_external_unique');
            $table->index(['tenant_id', 'status', 'placed_at']);
            $table->index(['tenant_id', 'marketplace_account_id', 'placed_at'], 'orders_tenant_account_placed_index');
            $table->index(['tenant_id', 'ship_by_at']);
            $table->index(['tenant_id', 'assigned_to']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('external_item_id', 64);
            $table->string('external_product_id', 64)->nullable();
            $table->string('external_sku_id', 64)->nullable();
            $table->foreignId('listing_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('seller_sku', 100)->nullable();
            $table->string('name');
            $table->string('variant_name')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('cost_snapshot', 18, 4)->nullable();
            $table->string('mapping_status', 20)->default('unmapped');
            $table->timestamps();
            $table->unique(['order_id', 'external_item_id']);
            $table->index(['tenant_id', 'product_variant_id']);
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('raw_status', 64)->nullable();
            $table->string('source', 20);
            $table->unsignedBigInteger('webhook_event_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('occurred_at', 6)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'order_id', 'id']);
        });

        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['tenant_id', 'order_id']);
        });

        // Customer returns, refunds and parcels coming back after a cancel or a failed delivery.
        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->string('type', 30);
            $table->string('status', 20);
            // Deduplicates automatic returns (cancel-after-ship, failed delivery, marketplace return id).
            $table->string('source_key', 100)->nullable();
            $table->string('external_return_id', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->decimal('refund_amount', 15, 2)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'order_id', 'source_key']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('order_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_qty')->default(0);
            $table->unsignedInteger('sellable_qty')->default(0);
            $table->unsignedInteger('damaged_qty')->default(0);
            $table->timestamps();
            $table->unique(['order_return_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        foreach (['order_return_lines', 'order_returns', 'order_notes', 'order_status_history', 'order_items', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
