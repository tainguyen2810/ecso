<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scan-to-pack (đóng gói bằng quét mã): a shelf location per SKU for the pick list, and a log of wrong or extra scans
 * so the shop can see which packer or which product keeps going wrong. Only adds a nullable column and a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('location', 50)->nullable()->after('barcode');
        });

        Schema::create('pack_scan_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 100);
            $table->string('kind', 10); // wrong: not in the order, extra: more than ordered
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_scan_errors');
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('location'));
    }
};
