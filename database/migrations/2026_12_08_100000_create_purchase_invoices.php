<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Hoá đơn mua vào": e-invoices suppliers issued to the business (XML files from the tax portal or the supplier),
 * checked, then turned into a purchase order or set aside ("Không đưa vào").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8)->default('vat');
            $table->string('series', 20);
            $table->string('number', 20);
            $table->date('issued_on')->nullable();
            $table->string('authority_code', 64)->nullable();
            $table->string('seller_tax_code', 20)->default('');
            $table->string('seller_name', 255)->default('');
            $table->string('seller_address', 255)->nullable();
            $table->string('seller_phone', 30)->nullable();
            $table->string('buyer_tax_code', 20)->nullable();
            $table->string('buyer_name', 255)->nullable();
            $table->string('currency', 3)->default('VND');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('shipping', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->json('lines');
            $table->json('issues')->nullable();
            $table->string('status', 12)->default('open');
            $table->string('note', 255)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'seller_tax_code', 'series', 'number'], 'purchase_invoices_unique_doc');
            $table->index(['tenant_id', 'issued_on']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
