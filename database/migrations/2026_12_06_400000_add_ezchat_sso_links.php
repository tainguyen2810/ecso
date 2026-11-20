<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt 17 "Đăng nhập bằng EzChat": the EzChat user and workspace a banhang user and business stand for, and a
 * subscription given by the EzChat plan ("gói tặng kèm", no invoices, ends with the EzChat plan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ezchat_user_id', 64)->nullable()->unique()->after('email');
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('ezchat_workspace_id', 64)->nullable()->unique()->after('slug');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('gift_source', 20)->nullable()->after('status');
            $table->string('gift_label', 100)->nullable()->after('gift_source');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn(['gift_source', 'gift_label']));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('ezchat_workspace_id'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('ezchat_user_id'));
    }
};
