<?php

use App\Modules\Marketplace\Connectors\Shopee\ShopeeConnector;
use App\Modules\Orders\Domain\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shopee "Chờ - Đang được kiểm tra bởi Shopee" (order_status PENDING, asked for with request_order_status_pending):
 * the order waits with those to pack; "Chuẩn bị hàng" is held back until Shopee releases it (READY_TO_SHIP).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('marketplace_status_maps')->insertOrIgnore([
            'marketplace' => ShopeeConnector::MARKETPLACE, 'raw_status' => ShopeeConnector::REVIEW_STATUS,
            'normalized_status' => OrderStatus::READY_TO_PACK, 'note' => 'Shopee đang kiểm tra', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('marketplace_status_maps')->where('marketplace', ShopeeConnector::MARKETPLACE)->where('raw_status', ShopeeConnector::REVIEW_STATUS)->delete();
    }
};
