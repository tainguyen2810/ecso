<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer type (person or business), where the customer came from, and invoice details; groups can be retired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('type', 16)->default('individual')->after('name');
            $table->string('source', 16)->nullable()->after('type');
            $table->string('invoice_name', 200)->nullable()->after('tax_code');
            $table->text('invoice_address')->nullable()->after('invoice_name'); // encrypted
            $table->string('invoice_email', 150)->nullable()->after('invoice_address');
            $table->index(['tenant_id', 'source']);
        });
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn(['type', 'source', 'invoice_name', 'invoice_address', 'invoice_email']);
        });
        Schema::table('customer_groups', fn (Blueprint $table) => $table->dropColumn('is_active'));
    }
};
