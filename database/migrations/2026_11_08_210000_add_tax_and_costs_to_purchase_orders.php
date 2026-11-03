<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Input VAT per purchase line and purchase costs (freight, handling) allocated into the landed cost of the goods.
 * Existing orders keep 0 % VAT and no costs, so their figures do not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(0)->after('unit_cost');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('subtotal', 15, 2)->default(0)->after('total');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('subtotal');
            $table->decimal('extra_cost', 15, 2)->default(0)->after('tax_amount');
            $table->string('extra_cost_note', 255)->nullable()->after('extra_cost');
            // Freight on the supplier's invoice is owed to the supplier; paid to a carrier it is not.
            $table->boolean('extra_cost_to_supplier')->default(false)->after('extra_cost_note');
            // Businesses that cannot deduct input VAT (direct method) carry it in the cost of goods.
            $table->boolean('vat_in_cost')->default(false)->after('extra_cost_to_supplier');
            $table->decimal('received_tax', 15, 2)->default(0)->after('received_value');
            $table->decimal('received_extra_cost', 15, 2)->default(0)->after('received_tax');
        });
        DB::table('purchase_orders')->update(['subtotal' => DB::raw('total')]);

        Schema::table('supplier_return_lines', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(0)->after('unit_cost');
        });
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->decimal('tax_amount', 15, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_returns', fn (Blueprint $table) => $table->dropColumn('tax_amount'));
        Schema::table('supplier_return_lines', fn (Blueprint $table) => $table->dropColumn('tax_rate'));
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropColumn(['subtotal', 'tax_amount', 'extra_cost', 'extra_cost_note', 'extra_cost_to_supplier', 'vat_in_cost', 'received_tax', 'received_extra_cost']));
        Schema::table('purchase_order_lines', fn (Blueprint $table) => $table->dropColumn('tax_rate'));
    }
};
