<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The basic menu (Cấu hình › Hiện tính năng nâng cao) is for new shops: every business that exists now keeps the
 * full menu it already knows. Owners can switch it off themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')->orderBy('id')->select(['id', 'settings'])->chunkById(200, function ($tenants) {
            foreach ($tenants as $t) {
                $settings = json_decode((string) ($t->settings ?? ''), true) ?: [];
                if (isset($settings['interface']['advanced_menu'])) {
                    continue;
                }
                $settings['interface'] = ['advanced_menu' => true] + ($settings['interface'] ?? []);
                DB::table('tenants')->where('id', $t->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void {}
};
