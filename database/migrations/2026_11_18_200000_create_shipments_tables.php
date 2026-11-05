<?php

use App\Modules\Orders\Services\ShipmentBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipments (vận đơn) and their history. Only adds tables: orders keep shipping_carrier / tracking_number, which
 * mirror each order's main shipment. Existing orders get their shipment from ecso:backfill-shipments, which only
 * inserts into these new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Carrier key shared with COD statements (ghn, viettelpost, spx, jt) or null when not one of them;
            // carrier_name keeps what the order says ("J&T Express", "Shopee Xpress", "Tự giao").
            $table->string('carrier', 20)->nullable();
            $table->string('carrier_name', 100)->nullable();
            $table->string('source', 20);
            $table->string('tracking_number', 100)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->string('status', 24);
            $table->string('raw_status', 64)->nullable();
            $table->string('service', 50)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('packages')->default(1);
            $table->unsignedInteger('weight_g')->nullable();
            $table->unsignedInteger('length_cm')->nullable();
            $table->unsignedInteger('width_cm')->nullable();
            $table->unsignedInteger('height_cm')->nullable();
            $table->decimal('cod_amount', 15, 2)->default(0);
            $table->decimal('cod_collected', 15, 2)->nullable();
            $table->string('cod_status', 16)->default('none');
            $table->string('payer', 10)->default('shop');
            // Display only (decision Q2): profit takes carrier fees from the COD reconciliation and manual vouchers.
            $table->decimal('fee_estimated', 15, 2)->nullable();
            $table->decimal('fee_actual', 15, 2)->nullable();
            $table->string('fee_status', 12)->default('unknown');
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('ship_by_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'status', 'last_event_at']);
            // Not unique yet: orders typed before the duplicate check may share a number. Uniqueness is checked on
            // the order (Tracking::assertUnique) until carrier sync needs (tenant, carrier, tracking) to be unique.
            $table->index(['tenant_id', 'tracking_number']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24);
            $table->string('raw_status', 64)->nullable();
            $table->timestamp('occurred_at', 6);
            $table->string('source', 16);
            $table->string('description', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dedupe_key', 120);
            $table->timestamp('created_at')->nullable();

            $table->unique(['shipment_id', 'dedupe_key']);
            $table->index(['tenant_id', 'occurred_at']);
        });

        // Direct call, not Artisan: the web installer runs migrations where console commands are not registered.
        app(ShipmentBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
    }
};
