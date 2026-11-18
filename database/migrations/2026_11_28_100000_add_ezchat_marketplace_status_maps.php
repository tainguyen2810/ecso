<?php

use App\Modules\Marketplace\Connectors\Shopee\ShopeeConnector;
use App\Modules\Marketplace\Connectors\TikTok\TikTokConnector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shopee and TikTok Shop are connected through EzChat (EZCHAT_URL): their order statuses get a status map.
 * Shopee order_status (Open Platform v2) and TikTok Shop order status (API 202309).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ([ShopeeConnector::MARKETPLACE => [ShopeeConnector::STATUSES, 'Shopee'], TikTokConnector::MARKETPLACE => [TikTokConnector::STATUSES, 'TikTok Shop']] as $marketplace => [$statuses, $note]) {
            DB::table('marketplace_status_maps')->insertOrIgnore(array_map(fn ($raw, $status) => [
                'marketplace' => $marketplace, 'raw_status' => $raw, 'normalized_status' => $status,
                'note' => $note, 'created_at' => $now, 'updated_at' => $now,
            ], array_keys($statuses), $statuses));
        }
    }

    public function down(): void
    {
        DB::table('marketplace_status_maps')->whereIn('marketplace', [ShopeeConnector::MARKETPLACE, TikTokConnector::MARKETPLACE])->delete();
    }
};
