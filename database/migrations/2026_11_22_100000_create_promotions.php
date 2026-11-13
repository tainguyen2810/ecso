<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions for the shop's own channels and POS (C4): discount on the order, discount on products, buy X get Y, and
 * codes. An order keeps what it received: the amount (folded into orders.discount so every report already counts it,
 * and kept apart in promotion_discount), the code typed and gift lines marked with their promotion.
 * Permission promotions.manage goes to the system roles and to every role that may already discount (orders.price).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('type', 12); // order | product | gift
            $table->string('code', 40)->nullable(); // null = applied by itself
            $table->string('discount_kind', 12)->default('percent'); // percent | amount | fixed_price
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('max_discount', 14, 2)->nullable();
            $table->decimal('min_subtotal', 14, 2)->default(0);
            $table->json('tiers')->nullable(); // [{min_subtotal, value}] better steps of an order discount
            $table->unsignedInteger('buy_qty')->nullable();
            $table->foreignId('gift_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('gift_qty')->nullable();
            $table->json('channel_ids')->nullable(); // null = every own channel
            $table->json('variant_ids')->nullable(); // null = every product
            $table->json('category_ids')->nullable();
            $table->json('customer_group_ids')->nullable(); // null = every customer, walk-ins too
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->string('status', 12)->default('active'); // active | paused
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('promotion_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2)->default(0); // money off, or the gifts' retail value
            $table->timestamps();
            $table->unique(['order_id', 'promotion_id']);
            $table->index(['tenant_id', 'promotion_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('promotion_discount', 14, 2)->default(0)->after('discount');
            $table->string('coupon_code', 40)->nullable()->after('promotion_discount');
            $table->boolean('promotions_off')->default(false)->after('coupon_code');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('promotion_id')->nullable()->after('discount')->constrained()->nullOnDelete();
        });

        (new AccessSeeder)->run();
        $ids = DB::table('permissions')->pluck('id', 'key');
        if (isset($ids['promotions.manage'], $ids['orders.price'])) {
            $roles = DB::table('role_permissions')->where('permission_id', $ids['orders.price'])->pluck('role_id');
            $have = DB::table('role_permissions')->where('permission_id', $ids['promotions.manage'])->pluck('role_id')->all();
            $rows = $roles->diff($have)->map(fn ($role) => ['role_id' => $role, 'permission_id' => $ids['promotions.manage']])->values()->all();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['promotion_discount', 'coupon_code', 'promotions_off']);
        });
        Schema::dropIfExists('promotion_usages');
        Schema::dropIfExists('promotions');
        $id = DB::table('permissions')->where('key', 'promotions.manage')->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
