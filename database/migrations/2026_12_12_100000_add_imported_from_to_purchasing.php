<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders, supplier returns and supplier payments brought over from the shop's previous software ("Chuyển dữ
 * liệu" › Nhà cung cấp và nhập hàng), e.g. 'sapo'. They are history: they never moved stock here, and the payments
 * never went through this shop's funds (they are left out of the cash book), but they make up the supplier's debt.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['purchase_orders', 'supplier_returns', 'supplier_payments'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->string('imported_from', 20)->nullable();
                $table->index(['tenant_id', 'imported_from'], $name.'_tenant_imported_index');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_tenant_imported_index');
                $table->dropColumn('imported_from');
            });
        }
    }
};
