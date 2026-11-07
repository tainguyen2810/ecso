<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carrier connections (kết nối hãng vận chuyển): the shop's own account at GHN, Viettel Post... ECSO only calls the
 * carrier's API with it; price and COD stay between the shop and the carrier. Shipments made through the API remember
 * the account, the code sent to the carrier (so a retry never makes a second parcel) and the last API error.
 * Only adds a table and nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('carrier', 20);
            $table->string('name', 150);
            $table->string('external_account', 100)->nullable();
            $table->string('environment', 12)->default('production');
            $table->string('status', 16)->default('connected');
            $table->text('credentials')->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->json('pickups')->nullable();
            $table->json('locations')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'carrier']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('carrier_account_id')->nullable()->after('source')->constrained()->nullOnDelete();
            $table->string('client_code', 40)->nullable()->unique()->after('carrier_account_id');
            $table->string('service_name', 100)->nullable()->after('service');
            $table->timestamp('expected_delivery_at')->nullable()->after('ship_by_at');
            $table->string('api_error', 255)->nullable()->after('failure_reason');
            $table->json('api_options')->nullable()->after('api_error');
            $table->timestamp('tracked_at')->nullable()->after('last_event_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carrier_account_id');
            $table->dropUnique(['client_code']);
            $table->dropColumn(['client_code', 'service_name', 'expected_delivery_at', 'api_error', 'api_options', 'tracked_at']);
        });
        Schema::dropIfExists('carrier_accounts');
    }
};
