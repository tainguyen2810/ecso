<?php

use App\Modules\Geo\Support\AdminUnits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard addresses: Vietnam's provinces and wards since 1 July 2025 (two levels, no district), and on orders,
 * customers, their saved addresses and warehouses the chosen province and ward plus the street part. The existing
 * text address column stays and holds the full address composed from these, so prints, exports and search keep
 * working; a row with a ward code is "standardised" and can later be sent to a carrier as is.
 */
return new class extends Migration
{
    private const TABLES = [
        'orders' => ['prefix' => 'shipping_', 'after' => 'shipping_address', 'encrypted' => true],
        'customers' => ['prefix' => '', 'after' => 'address', 'encrypted' => true],
        'customer_addresses' => ['prefix' => '', 'after' => 'address', 'encrypted' => true],
        'warehouses' => ['prefix' => '', 'after' => 'address', 'encrypted' => false],
    ];

    public function up(): void
    {
        Schema::create('geo_provinces', function (Blueprint $table) {
            $table->string('code', 3)->primary();
            $table->string('name', 100);
            $table->string('short_name', 100);
            $table->string('type', 20); // city | province
            $table->json('former')->nullable(); // provinces merged into it on 1 July 2025
        });

        Schema::create('geo_wards', function (Blueprint $table) {
            $table->string('code', 6)->primary();
            $table->string('province_code', 3)->index();
            $table->string('name', 150);
            $table->json('former')->nullable(); // [[old ward, old district], ...]
            $table->string('former_hint', 1000)->nullable();
            $table->text('search'); // name and former names without marks
        });

        AdminUnits::load();

        foreach (self::TABLES as $name => $o) {
            Schema::table($name, function (Blueprint $table) use ($o) {
                // The street part (house number, alley, street, building) is personal data: encrypted like the address.
                $o['encrypted'] ? $table->text($o['prefix'].'street')->nullable()->after($o['after']) : $table->string($o['prefix'].'street')->nullable()->after($o['after']);
                $table->string($o['prefix'].'province_code', 3)->nullable()->after($o['prefix'].'street');
                $table->string($o['prefix'].'ward_code', 6)->nullable()->after($o['prefix'].'province_code');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name => $o) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn([$o['prefix'].'street', $o['prefix'].'province_code', $o['prefix'].'ward_code']));
        }
        Schema::dropIfExists('geo_wards');
        Schema::dropIfExists('geo_provinces');
    }
};
