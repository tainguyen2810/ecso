<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Đợt 15 "Bản tin 8h sáng": each phone that gets pushes also gets the morning summary unless switched off. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->boolean('morning_brief')->default(true)->after('device');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('morning_brief');
        });
    }
};
