<?php

use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WooCommerce as a sales channel: a store is a marketplace account with its own website URL, per-store sync
 * settings and a per-store webhook secret. Woo statuses are documented (woocommerce.github.io/woocommerce-rest-api-docs),
 * so they get a status map; ECSO's packing stages are kept in the order meta "_ecso_stage" (see the connector).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->string('base_url', 255)->nullable()->after('region');
            $table->json('settings')->nullable()->after('sync_enabled');
        });

        Schema::table('marketplace_credentials', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->after('refresh_token');
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('external_product_id', 64)->nullable()->after('external_order_id');
        });

        // Regular and sale price as the channel shows them (price = what a buyer pays now).
        Schema::table('listing_variants', function (Blueprint $table) {
            $table->decimal('regular_price', 15, 2)->nullable()->after('price');
            $table->decimal('sale_price', 15, 2)->nullable()->after('regular_price');
        });

        $now = now();
        DB::table('marketplace_status_maps')->insertOrIgnore(array_map(fn ($raw, $status) => [
            'marketplace' => WooCommerceConnector::MARKETPLACE, 'raw_status' => $raw, 'normalized_status' => $status,
            'note' => 'WooCommerce', 'created_at' => $now, 'updated_at' => $now,
        ], array_keys(WooCommerceConnector::STATUSES), WooCommerceConnector::STATUSES));
    }

    public function down(): void
    {
        DB::table('marketplace_status_maps')->where('marketplace', WooCommerceConnector::MARKETPLACE)->delete();
        Schema::table('listing_variants', fn (Blueprint $table) => $table->dropColumn(['regular_price', 'sale_price']));
        Schema::table('webhook_events', fn (Blueprint $table) => $table->dropColumn('external_product_id'));
        Schema::table('marketplace_credentials', fn (Blueprint $table) => $table->dropColumn('webhook_secret'));
        Schema::table('marketplace_accounts', fn (Blueprint $table) => $table->dropColumn(['base_url', 'settings']));
    }
};
