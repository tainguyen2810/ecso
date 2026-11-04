<?php

use App\Modules\Sales\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lookup key of the buyer's phone on an order (the phone itself stays encrypted), so the order list finds an order by
 * the phone of a walk-in buyer who was never saved as a customer. Existing manual orders are filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('buyer_phone_hash', 64)->nullable()->after('buyer_phone');
            $table->index(['tenant_id', 'buyer_phone_hash']);
        });

        DB::table('orders')->whereNotNull('buyer_phone')->select(['id', 'buyer_phone'])->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        $phone = Crypt::decryptString($row->buyer_phone);
                    } catch (Throwable) {
                        continue; // unreadable (other key): left without a lookup key
                    }
                    DB::table('orders')->where('id', $row->id)->update(['buyer_phone_hash' => PhoneNumber::hash(PhoneNumber::normalize($phone))]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'buyer_phone_hash']);
            $table->dropColumn('buyer_phone_hash');
        });
    }
};
