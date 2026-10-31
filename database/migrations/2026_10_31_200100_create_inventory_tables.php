<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('address')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('allow_negative')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
        });

        // Running balance per (warehouse, SKU). Always derivable from stock_movements.
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->unsignedInteger('safety_stock')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'warehouse_id', 'product_variant_id'], 'stock_balances_unique');
            $table->index(['tenant_id', 'product_variant_id']);
        });

        // Immutable ledger: never updated or deleted.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->integer('on_hand_delta');
            $table->integer('reserved_delta');
            $table->integer('on_hand_after');
            $table->integer('reserved_after');
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 191);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'product_variant_id', 'created_at']);
            $table->index(['tenant_id', 'warehouse_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            // The thing stock is held for (order item from Phase 3).
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->unsignedInteger('quantity');
            // Requested - reserved when stock was short (order already exists on the marketplace).
            $table->unsignedInteger('shortage')->default(0);
            $table->string('status', 20); // active | consumed | released
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'reference_type', 'reference_id', 'warehouse_id'], 'stock_reservations_reference_unique');
            $table->index(['tenant_id', 'product_variant_id', 'status']);
        });

        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 20); // pending | approved | rejected | cancelled
            $table->string('reason');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('inventory_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            // delta: +/- quantity. count: physical count, delta computed against on_hand at approval time.
            $table->string('mode', 10);
            $table->integer('quantity');
            $table->integer('applied_delta')->nullable();
            $table->integer('on_hand_before')->nullable();
            $table->unique(['inventory_adjustment_id', 'product_variant_id'], 'adjustment_lines_unique');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 20); // draft | in_transit | received | cancelled
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unique(['stock_transfer_id', 'product_variant_id'], 'transfer_lines_unique');
        });

        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('source', 20)->default('manual'); // manual | opening | purchase (Phase 6)
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('stock_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 18, 4);
        });

        // Per-tenant document counters (ADJ-000001, TRF-000001...).
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 10);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->primary(['tenant_id', 'prefix']);
        });
    }

    public function down(): void
    {
        foreach (['document_sequences', 'stock_receipt_lines', 'stock_receipts', 'stock_transfer_lines', 'stock_transfers',
            'inventory_adjustment_lines', 'inventory_adjustments', 'stock_reservations', 'stock_movements', 'stock_balances', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
