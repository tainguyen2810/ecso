<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier invoice details on a purchase order (like Sapo's "Nhập hàng"): invoice date, the supplier's reference,
 * an order-level discount (spread into the landed cost by value) and tags. Existing orders get no discount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->date('invoice_date')->nullable()->after('expected_at');
            $table->string('reference', 100)->nullable()->after('invoice_date');
            $table->decimal('discount', 15, 2)->default(0)->after('tax_amount');
            $table->json('tags')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['invoice_date', 'reference', 'discount', 'tags']);
        });
    }
};
