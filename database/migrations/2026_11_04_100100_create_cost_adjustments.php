<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost price adjustment documents (CAD-000001): set a new average cost for SKUs, e.g. after a wrong opening cost
 * or a supplier rebate. Applied when created; the old cost is kept on the line for the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_adjustments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->string('note', 1000)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('cost_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('old_cost', 18, 4);
            $table->decimal('new_cost', 18, 4);
            $table->integer('on_hand')->default(0);
            $table->unique(['cost_adjustment_id', 'product_variant_id'], 'cost_adjustment_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_adjustment_lines');
        Schema::dropIfExists('cost_adjustments');
    }
};
