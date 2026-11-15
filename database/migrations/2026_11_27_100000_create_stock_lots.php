<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lots and expiry dates (lô / hạn sử dụng).
 *
 *  - products: a product opts in with track_lots and carries its expiry rules (warn N days before, stop selling
 *    under M days left, default shelf life used to fill the expiry date from the manufacturing date).
 *  - stock_lots: quantity per (warehouse, SKU, lot). For a tracked SKU the lots of a warehouse add up to its
 *    stock_balances row; lot_no '' is "Chưa rõ lô" (stock from before tracking, or received without a lot).
 *  - stock_lot_movements: the lot part of each stock movement. Rows carrying a reservation id say which lots an
 *    order line holds or shipped, so a cancel or return goes back to the same lots.
 *  - receipt and transfer lines keep the lot split shown on the document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('track_lots')->default(false)->after('type');
            $table->unsignedSmallInteger('expiry_warn_days')->nullable()->after('track_lots');
            $table->unsignedSmallInteger('expiry_block_days')->nullable()->after('expiry_warn_days');
            $table->unsignedSmallInteger('shelf_life_months')->nullable()->after('expiry_block_days');
            $table->boolean('require_mfg_date')->default(false)->after('shelf_life_months');
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('lot_no', 64)->default('');
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->integer('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->timestamp('first_received_at')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_variant_id', 'lot_no'], 'stock_lots_unique');
            $table->index(['tenant_id', 'expiry_date']);
            $table->index(['product_variant_id', 'expiry_date']);
        });

        Schema::create('stock_lot_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('on_hand_delta');
            $table->integer('reserved_delta');
            $table->timestamp('created_at')->nullable();

            $table->index(['stock_reservation_id', 'stock_lot_id']);
        });

        Schema::table('stock_receipt_lines', fn (Blueprint $table) => $table->json('lots')->nullable());
        Schema::table('stock_transfer_lines', fn (Blueprint $table) => $table->json('lots')->nullable());
    }

    public function down(): void
    {
        Schema::table('stock_transfer_lines', fn (Blueprint $table) => $table->dropColumn('lots'));
        Schema::table('stock_receipt_lines', fn (Blueprint $table) => $table->dropColumn('lots'));
        Schema::dropIfExists('stock_lot_movements');
        Schema::dropIfExists('stock_lots');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['track_lots', 'expiry_warn_days', 'expiry_block_days', 'shelf_life_months', 'require_mfg_date']));
    }
};
