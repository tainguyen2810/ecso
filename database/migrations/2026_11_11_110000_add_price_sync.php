<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-way price sync with WooCommerce (round 2): who holds the price of a SKU ("Nguồn giá"), what ECSO last pushed
 * to each channel SKU (loop guard), and a history of every price change with the queue of prices waiting for approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // auto = ECSO while the SKU has stock, the website otherwise; ecso / woo = fixed by the user.
            $table->string('price_source', 8)->default('auto')->after('ref_price');
            $table->string('price_updated_from', 30)->nullable()->after('price_source');
            $table->timestamp('price_updated_at')->nullable()->after('price_updated_from');
        });

        Schema::table('listing_variants', function (Blueprint $table) {
            $table->boolean('price_locked')->default(false)->after('sale_price');
            $table->decimal('target_price', 15, 2)->nullable()->after('price_locked');
            $table->decimal('pushed_price', 15, 2)->nullable()->after('target_price');
            $table->timestamp('price_pushed_at')->nullable()->after('pushed_price');
            $table->string('price_push_status', 20)->nullable()->after('price_pushed_at');
            $table->string('price_push_error', 255)->nullable()->after('price_push_status');
            $table->index(['marketplace_account_id', 'price_push_status']);
        });

        Schema::create('price_changes', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('listing_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('old_price', 15, 2)->nullable();
            $table->decimal('new_price', 15, 2);
            // ecso = changed in ECSO; channel = taken from (or attempted on) a website/marketplace
            $table->string('source', 20);
            // applied | pending | rejected | reverted (a change on the website undone because ECSO holds the price)
            $table->string('status', 20);
            $table->string('reason', 255)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'product_variant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_changes');
        Schema::table('listing_variants', function (Blueprint $table) {
            $table->dropIndex(['marketplace_account_id', 'price_push_status']);
            $table->dropColumn(['price_locked', 'target_price', 'pushed_price', 'price_pushed_at', 'price_push_status', 'price_push_error']);
        });
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn(['price_source', 'price_updated_from', 'price_updated_at']));
    }
};
