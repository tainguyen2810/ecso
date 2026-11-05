<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-channel stock allocation (INVENTORY-ENGINE §6): how much of a SKU's sellable stock each shop publishes.
 * One row per shop default (product_variant_id NULL, scope 'shop') and per SKU override (scope 'v<id>').
 * No row means "publish everything", which is how every shop worked before, so existing shops keep publishing
 * the same numbers. Only the published quantity is affected; internal stock is never read from or written by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            // 'shop' or 'v<variant id>': a unique key that a NULL variant id cannot give.
            $table->string('scope', 24);
            $table->string('mode', 10); // all | percent | fixed | none
            $table->unsignedInteger('value')->nullable();
            $table->unsignedInteger('buffer_qty')->default(0);
            $table->unsignedInteger('max_qty')->nullable();
            $table->timestamps();

            $table->unique(['marketplace_account_id', 'scope']);
            $table->index(['tenant_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_allocations');
    }
};
