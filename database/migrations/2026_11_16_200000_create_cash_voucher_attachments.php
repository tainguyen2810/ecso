<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files kept with a voucher (photo of the invoice, bank transfer slip). They are stored on the private disk and only
 * served to members who may see the voucher. Removing one only hides it: the file and the row stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_voucher_attachments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_voucher_id')->constrained('cash_vouchers')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'cash_voucher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_voucher_attachments');
    }
};
