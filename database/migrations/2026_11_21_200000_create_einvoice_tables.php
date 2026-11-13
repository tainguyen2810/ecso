<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-invoices (C2): the shop's own account at an e-invoice provider (SePay eInvoice first) and the invoices issued
 * for orders. ECSO only sends the order's data; the provider signs and files the invoice with the tax authority.
 * Permission einvoice.issue goes to the system roles and to every role that can already take payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('einvoice_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('environment', 12)->default('production');
            $table->text('credentials')->nullable(); // encrypted
            $table->string('external_account', 100)->nullable(); // the provider's account id (SePay provider_account_id)
            $table->string('provider_name', 60)->nullable(); // who signs behind it (matbao...)
            $table->json('templates')->nullable();
            $table->string('template_code', 10)->nullable();
            $table->string('invoice_series', 20)->nullable();
            $table->string('issue_mode', 12)->default('manual'); // manual | paid | completed
            $table->boolean('send_email')->default(true);
            $table->string('status', 16)->default('connected');
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('einvoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('einvoice_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('reference_code', 64);
            $table->string('tracking_code', 100)->nullable();
            $table->string('status', 12); // pending | issued | failed
            $table->string('template_code', 10)->nullable();
            $table->string('invoice_series', 20)->nullable();
            $table->string('invoice_number', 30)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->text('pdf_url')->nullable();
            $table->text('xml_url')->nullable();
            $table->text('buyer')->nullable(); // encrypted json
            $table->decimal('total', 15, 2)->default(0);
            $table->string('message', 500)->nullable();
            $table->unsignedSmallInteger('checks')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'reference_code']);
            $table->index(['tenant_id', 'order_id']);
        });

        (new AccessSeeder)->run();
        $ids = DB::table('permissions')->pluck('id', 'key');
        if (isset($ids['einvoice.issue'], $ids['orders.payment'])) {
            $roles = DB::table('role_permissions')->where('permission_id', $ids['orders.payment'])->pluck('role_id');
            $have = DB::table('role_permissions')->where('permission_id', $ids['einvoice.issue'])->pluck('role_id')->all();
            $rows = $roles->diff($have)->map(fn ($role) => ['role_id' => $role, 'permission_id' => $ids['einvoice.issue']])->values()->all();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoices');
        Schema::dropIfExists('einvoice_accounts');
        $id = DB::table('permissions')->where('key', 'einvoice.issue')->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
