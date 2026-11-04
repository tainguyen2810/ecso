<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One customer across channels: the ids a buyer has on each connected store (customer_identities), and a record of
 * every merge of two customer cards (customer_merges, with the merged card kept as an encrypted snapshot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('source', 30); // marketplace key: woocommerce, shopee, tiktok…
            $table->unsignedBigInteger('marketplace_account_id')->nullable(); // the store, when a source has several
            $table->string('external_id', 100);
            $table->string('label', 150)->nullable(); // what the store shows: username, name
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'source', 'marketplace_account_id', 'external_id'], 'customer_identities_unique');
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('email_key', 150)->nullable()->after('email'); // lower-cased email, to find a buyer by it
            $table->index(['tenant_id', 'email_key']);
        });
        DB::table('customers')->whereNotNull('email')->update(['email_key' => DB::raw('LOWER(TRIM(email))')]);

        Schema::create('customer_merges', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); // the card that was kept
            $table->unsignedBigInteger('merged_customer_id'); // id of the card that was merged in (now gone)
            $table->string('merged_code', 32);
            $table->string('merged_name', 150);
            $table->text('snapshot'); // encrypted json of the merged card, so nothing is lost
            $table->json('moved'); // counts of what moved: orders, payments, addresses…
            $table->json('choices')->nullable(); // fields taken from the merged card
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'merged_customer_id']);
        });

        (new AccessSeeder)->run();
        // A business's own roles that could delete customers can also merge them (merging removes a card).
        $from = DB::table('permissions')->where('key', 'customers.delete')->value('id');
        $to = DB::table('permissions')->where('key', 'customers.merge')->value('id');
        if ($from && $to) {
            $roles = DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->whereNotNull('roles.tenant_id')->where('permission_id', $from)->pluck('role_id');
            foreach ($roles as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $to]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'customers.merge')->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        Schema::dropIfExists('customer_merges');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'email_key']);
            $table->dropColumn('email_key');
        });
        Schema::dropIfExists('customer_identities');
    }
};
