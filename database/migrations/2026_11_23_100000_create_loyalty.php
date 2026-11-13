<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tích điểm và hạng khách (C5). Points are a ledger per customer (earned when an own-channel order completes, taken
 * back on returns, spent on orders, given by hand, expired); customers.loyalty_points is its running balance.
 * Tiers are reached by spending over the last 12 months; each can be tied to a customer group, so the group's price
 * policy and discount apply to the tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('min_spent', 15, 2)->default(0);
            $table->decimal('point_multiplier', 5, 2)->default(1);
            $table->foreignId('customer_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('color', 16)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'min_spent']);
        });

        Schema::create('loyalty_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // earn | return | redeem | redeem_back | adjust | expire | tier
            $table->string('kind', 12);
            $table->integer('points');
            $table->decimal('multiplier', 5, 2)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['customer_id', 'id']);
            $table->index(['order_id', 'kind']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->integer('loyalty_points')->default(0);
            $table->foreignId('loyalty_tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('points_used')->default(0)->after('promotions_off');
            $table->decimal('points_discount', 14, 2)->default(0)->after('points_used');
        });
        (new AccessSeeder)->run(); // promotions.manage now also covers the loyalty program
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['points_used', 'points_discount']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loyalty_tier_id');
            $table->dropColumn('loyalty_points');
        });
        Schema::dropIfExists('loyalty_entries');
        Schema::dropIfExists('loyalty_tiers');
    }
};
