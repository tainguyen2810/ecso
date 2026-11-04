<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business configuration like Sapo's "Cấu hình" (store details, tax and price defaults, payments, sales rules, print
 * templates) as one JSON column, plus the store logo printed on invoices. Adds the "orders.price" permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('currency');
            $table->string('logo_path')->nullable()->after('settings');
        });

        (new AccessSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['settings', 'logo_path']);
        });
    }
};
