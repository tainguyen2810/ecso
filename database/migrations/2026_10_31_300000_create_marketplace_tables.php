<?php

use App\Modules\Marketplace\Connectors\Fake\FakeConnector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A connected shop. One marketplace shop belongs to exactly one tenant.
        Schema::create('marketplace_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('marketplace', 20);
            $table->string('external_shop_id', 64);
            $table->string('shop_name');
            $table->string('region', 8)->nullable();
            $table->string('status', 20)->default('connected');
            // Warehouse that reserves stock for this shop's orders and feeds its published stock.
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('sync_enabled')->default(true);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('circuit_open_until')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['marketplace', 'external_shop_id']);
            $table->index(['tenant_id', 'status']);
        });

        // API tokens, encrypted at rest (Laravel encrypted casts, APP_KEY). Never returned by the API.
        Schema::create('marketplace_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->unsignedSmallInteger('key_version')->default(1);
            $table->timestamps();
        });

        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_product_id', 64);
            $table->string('title');
            $table->string('status', 30)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_account_id', 'external_product_id'], 'listings_account_product_unique');
        });

        Schema::create('listing_variants', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('marketplace_listings')->cascadeOnDelete();
            $table->string('external_sku_id', 64);
            $table->string('seller_sku', 100)->nullable();
            $table->string('name')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->integer('marketplace_stock')->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mapping_status', 20)->default('unmapped');
            $table->foreignId('mapped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mapped_at')->nullable();
            $table->integer('last_pushed_qty')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('push_status', 20)->nullable();
            $table->string('push_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['marketplace_account_id', 'external_sku_id'], 'listing_variants_account_sku_unique');
            $table->index(['tenant_id', 'product_variant_id']);
            $table->index(['tenant_id', 'mapping_status']);
        });

        // raw marketplace status -> internal status. Seeded per marketplace only from official docs.
        Schema::create('marketplace_status_maps', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace', 20);
            $table->string('raw_status', 64);
            $table->string('normalized_status', 32);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['marketplace', 'raw_status']);
        });

        // Reference data for the Fake marketplace only. Real marketplaces get their rows once their statuses are
        // verified against official documentation (API-CAPABILITY-MATRIX); until then they map to UNKNOWN_STATUS.
        $now = now();
        DB::table('marketplace_status_maps')->insert(array_map(fn ($raw, $status) => [
            'marketplace' => 'fake', 'raw_status' => $raw, 'normalized_status' => $status, 'note' => 'Sàn giả lập',
            'created_at' => $now, 'updated_at' => $now,
        ], array_keys(FakeConnector::STATUSES), FakeConnector::STATUSES));

        // Inbox: every webhook is stored before it is processed. tenant_id is filled once the shop is resolved.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace', 20);
            $table->string('dedupe_key', 191);
            $table->foreignId('marketplace_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 64)->nullable();
            $table->string('external_event_id', 128)->nullable();
            $table->string('external_shop_id', 64)->nullable();
            $table->string('external_order_id', 64)->nullable();
            $table->char('payload_hash', 64);
            $table->boolean('signature_valid');
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('result', 500)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unique(['marketplace', 'dedupe_key']);
            $table->index(['status', 'received_at']);
            $table->index(['tenant_id', 'received_at']);
        });

        // One row per listing variant: later changes overwrite desired_qty (coalescing).
        Schema::create('inventory_sync_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_variant_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('desired_qty');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['marketplace_account_id', 'status']);
        });

        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 32);
            $table->string('cursor')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_account_id', 'resource']);
        });

        // State of the FAKE marketplace used in development and tests. It plays the remote side; it is not
        // tenant data and is never used for a real shop.
        Schema::create('fake_marketplace_products', function (Blueprint $table) {
            $table->id();
            $table->string('shop_id', 64);
            $table->string('product_id', 64);
            $table->string('sku_id', 64);
            $table->string('seller_sku', 100)->nullable();
            $table->string('title');
            $table->string('variant_name')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->timestamp('stock_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'sku_id']);
        });

        Schema::create('fake_marketplace_orders', function (Blueprint $table) {
            $table->id();
            $table->string('shop_id', 64);
            $table->string('order_sn', 64)->unique();
            $table->string('status', 32);
            $table->json('data');
            $table->timestamp('update_time', 6);
            $table->timestamps();
            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        foreach (['fake_marketplace_orders', 'fake_marketplace_products', 'sync_cursors', 'inventory_sync_outbox',
            'webhook_events', 'marketplace_status_maps', 'listing_variants', 'marketplace_listings',
            'marketplace_credentials', 'marketplace_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
