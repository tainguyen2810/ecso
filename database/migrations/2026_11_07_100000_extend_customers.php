<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Customer groups become their own records (default price tier, discount, payment method), and the customer profile
 * gains birthday, gender, tax code, staff in charge, tags, a trading status, extra delivery addresses and notes.
 * The old free-text `customers.group` values are moved into groups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            // Which product price an order for this group starts from: retail (ref_price) or wholesale.
            $table->string('price_tier', 16)->default('retail');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->string('payment_method', 20)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('customer_group_id')->nullable()->after('address')->constrained()->nullOnDelete();
            $table->date('birthday')->nullable()->after('email');
            $table->string('gender', 8)->nullable()->after('birthday');
            $table->string('tax_code', 20)->nullable()->after('gender');
            $table->foreignId('assigned_user_id')->nullable()->after('customer_group_id')->constrained('users')->nullOnDelete();
            $table->json('tags')->nullable()->after('assigned_user_id');
            $table->string('status', 16)->default('active')->after('tags');
            $table->index(['tenant_id', 'status']);
        });

        $now = now();
        DB::table('customers')->whereNotNull('group')->where('group', '!=', '')
            ->select('tenant_id', 'group')->distinct()->orderBy('tenant_id')->get()
            ->each(function ($row) use ($now) {
                $code = Str::upper(Str::limit(Str::slug($row->group, ''), 28, '')) ?: 'NHOM';
                $taken = DB::table('customer_groups')->where('tenant_id', $row->tenant_id)->pluck('code')->all();
                for ($i = 2, $base = $code; in_array($code, $taken, true); $i++) {
                    $code = $base.$i;
                }
                $id = DB::table('customer_groups')->insertGetId([
                    'ulid' => (string) Str::ulid(), 'tenant_id' => $row->tenant_id, 'code' => $code, 'name' => $row->group,
                    'price_tier' => 'retail', 'discount_percent' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('customers')->where('tenant_id', $row->tenant_id)->where('group', $row->group)->update(['customer_group_id' => $id]);
            });

        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('group'));

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50)->nullable();
            $table->string('recipient', 150)->nullable();
            // Personal data, encrypted like the customer's own phone and address.
            $table->text('phone')->nullable();
            $table->text('address');
            $table->timestamps();
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_addresses');

        Schema::table('customers', fn (Blueprint $table) => $table->string('group', 50)->nullable()->after('address'));
        DB::table('customers')->whereNotNull('customer_group_id')->update([
            'group' => DB::raw('(SELECT name FROM customer_groups WHERE customer_groups.id = customers.customer_group_id)'),
        ]);

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropConstrainedForeignId('customer_group_id');
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn(['birthday', 'gender', 'tax_code', 'tags', 'status']);
        });

        Schema::dropIfExists('customer_groups');
    }
};
