<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier shifts at the counter: opening float, cash put into or taken out of the drawer, and the count at closing
 * against the cash the system expects. Money received during the shift stays in order_payments; a shift is a window
 * (cashier + opened_at..closed_at) over it, so sales code does not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10); // open | closed
            $table->timestamp('opened_at');
            $table->decimal('opening_cash', 15, 2)->default(0);
            $table->string('opening_note', 500)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            // Frozen at closing so later voids do not rewrite a closed drawer count.
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();
            $table->decimal('handed_over', 15, 2)->nullable();
            $table->json('denominations')->nullable();
            $table->json('totals')->nullable();
            $table->string('closing_note', 500)->nullable();
            // Set while open, null when closed: one open shift per cashier enforced by the database.
            $table->unsignedBigInteger('open_user_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'open_user_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'opened_at']);
        });

        Schema::create('cash_shift_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_shift_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 3); // in | out
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // New permission orders.shifts for existing tenants' system roles.
        (new AccessSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_shift_movements');
        Schema::dropIfExists('cash_shifts');
    }
};
