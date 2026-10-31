<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // A cheaper plan chosen by the tenant, applied at the end of the paid period.
            $table->foreignId('pending_plan_id')->nullable()->after('plan_id')->constrained('plans')->nullOnDelete();
            $table->string('pending_billing_cycle', 10)->nullable()->after('pending_plan_id');
            $table->timestamp('trial_used_at')->nullable()->after('trial_ends_at');
            $table->timestamp('past_due_since')->nullable()->after('cancel_at');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30)->unique();
            $table->string('kind', 20); // subscribe | upgrade | renewal
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_cycle', 10);
            $table->decimal('subtotal', 15, 2);
            // Unused value of the current paid period, deducted on upgrade.
            $table->decimal('credit', 15, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('VND');
            $table->string('status', 20); // pending | paid | cancelled
            // Code the customer writes in the bank transfer memo; matches the payment to this invoice.
            $table->string('transfer_code', 20)->unique();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // Money received from a payment provider. One row per provider transaction: the unique key makes
        // recording a payment idempotent (an IPN or an admin confirmation sent twice is applied once).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('provider_txn_id', 100);
            $table->decimal('amount', 15, 2);
            $table->string('status', 20); // applied | underpaid | unapplied
            $table->timestamp('received_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_txn_id']);
            $table->index(['tenant_id', 'invoice_id']);
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 50);
            $table->char('period', 7); // YYYY-MM
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'metric', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_plan_id');
            $table->dropColumn(['pending_billing_cycle', 'trial_used_at', 'past_due_since']);
        });
    }
};
