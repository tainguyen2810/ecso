<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt 16 "Đối soát tồn với sàn mỗi đêm": one row per shop and run — the marketplace's stock read again and compared
 * with what ECSO publishes; SKUs that differed are listed (up to 200) and set right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('running');
            $table->string('trigger', 16)->default('nightly');
            $table->unsignedInteger('checked')->default(0);
            $table->unsignedInteger('mismatched')->default(0);
            $table->unsignedInteger('fixed')->default(0);
            $table->json('items')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['marketplace_account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_checks');
    }
};
