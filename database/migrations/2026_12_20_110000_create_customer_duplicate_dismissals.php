<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khách trùng: a group of cards someone marked "Không phải trùng". The key is a hash of the cards in the group, so
 * the group stays hidden while it holds the same cards and shows again if another card joins it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_duplicate_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('group_key', 40);
            $table->string('reason', 12);
            $table->string('value', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['tenant_id', 'group_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_duplicate_dismissals');
    }
};
