<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhân viên: chấm công, hoa hồng, bảng lương.
 *  - staff_profiles: how each member is paid (monthly or hourly), allowance, insured salary, dependents, shift hours;
 *  - attendance_entries: days checked in on the phone or set by a manager (the counter's cash shifts count by themselves);
 *  - commission_rules: % of counter / own-channel sales, an amount per unit of a category, a store bonus;
 *  - payroll_runs: a month's payroll once approved (lines frozen) and paid (vouchers in the cash book).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100)->nullable();
            $table->string('pay_type', 8)->default('month'); // month | hour
            $table->decimal('base_salary', 15, 2)->default(0);
            $table->decimal('hourly_rate', 15, 2)->default(0);
            $table->unsignedTinyInteger('standard_days')->default(26);
            $table->decimal('allowance', 15, 2)->default(0);
            $table->decimal('insured_salary', 15, 2)->default(0);
            $table->unsignedTinyInteger('dependents')->default(0);
            $table->decimal('sales_target', 15, 2)->default(0);
            $table->string('shift_start', 5)->default('08:00');
            $table->string('shift_end', 5)->default('17:00');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('attendance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();
            $table->string('status', 8)->default('work'); // work | leave (paid) | off (unpaid)
            $table->string('source', 8)->default('phone'); // phone | manual
            $table->boolean('excuse_late')->default(false);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'day']);
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 16); // counter | online | category_unit | store_bonus
            $table->decimal('rate', 6, 3)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('threshold', 15, 2)->default(0);
            $table->json('user_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->string('status', 10)->default('draft'); // draft | approved | paid
            $table->json('adjustments')->nullable();
            $table->json('lines')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'period']);
        });

        (new AccessSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('attendance_entries');
        Schema::dropIfExists('staff_profiles');
    }
};
