<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer care: what kind of contact a note records, follow-up tasks (call back, send a quote), and groups that
 * customers join by themselves once they have bought enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notes', function (Blueprint $table) {
            $table->string('kind', 16)->default('note')->after('customer_id'); // note | call | message | meeting | complaint
        });

        Schema::create('customer_tasks', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->timestamp('due_at');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'done_at', 'due_at']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::table('customer_groups', function (Blueprint $table) {
            // A customer with no group joins this one once they reach these figures (both when both are set).
            $table->decimal('auto_min_spent', 15, 2)->nullable()->after('credit_limit');
            $table->unsignedInteger('auto_min_orders')->nullable()->after('auto_min_spent');
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', fn (Blueprint $table) => $table->dropColumn(['auto_min_spent', 'auto_min_orders']));
        Schema::dropIfExists('customer_tasks');
        Schema::table('customer_notes', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
