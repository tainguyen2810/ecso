<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 9: own sales channels (shop counter, Facebook, Zalo, website...), customers, manual orders and payments.
 * Orders no longer require a marketplace shop: an order belongs either to a marketplace account or to a sales channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 20); // pos | store | facebook | zalo | website | phone | other
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 150);
            // Personal data: phone and address are encrypted; the phone is looked up through a keyed hash.
            $table->text('phone')->nullable();
            $table->char('phone_hash', 64)->nullable();
            $table->string('phone_last4', 4)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('group', 50)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'phone_hash']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('marketplace_account_id')->nullable()->change();
            $table->foreignId('sales_channel_id')->nullable()->after('marketplace_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->after('sales_channel_id')->constrained()->nullOnDelete();
            // Manual orders keep the full phone (encrypted) for delivery; marketplace orders keep the masked one only.
            $table->text('buyer_phone')->nullable()->after('buyer_phone_masked');
            $table->decimal('paid_amount', 15, 2)->default(0)->after('total');
            $table->decimal('refunded_amount', 15, 2)->default(0)->after('paid_amount');
            // null for marketplace orders (the marketplace collects the money); unpaid | partial | paid otherwise.
            $table->string('payment_status', 20)->nullable()->after('refunded_amount');
            $table->foreignId('created_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'sales_channel_id', 'placed_at']);
            $table->index(['tenant_id', 'customer_id', 'placed_at']);
            $table->index(['tenant_id', 'payment_status']);
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10); // payment | refund
            $table->string('method', 20); // cash | bank_transfer | card | ewallet | cod | other
            $table->decimal('amount', 15, 2);
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'paid_at']);
            $table->index(['tenant_id', 'customer_id']);
        });

        // Existing businesses get the default shop-counter channel (new ones get it on creation).
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('sales_channels')->insertOrIgnore([
                'ulid' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'name' => 'Bán tại cửa hàng', 'type' => 'pos',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // New permission keys reach the system roles on deploy (the updater runs migrations, not seeders).
        (new AccessSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'sales_channel_id', 'placed_at']);
            $table->dropIndex(['tenant_id', 'customer_id', 'placed_at']);
            $table->dropIndex(['tenant_id', 'payment_status']);
            $table->dropConstrainedForeignId('sales_channel_id');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['buyer_phone', 'paid_amount', 'refunded_amount', 'payment_status']);
        });
        Schema::dropIfExists('customers');
        Schema::dropIfExists('sales_channels');
    }
};
