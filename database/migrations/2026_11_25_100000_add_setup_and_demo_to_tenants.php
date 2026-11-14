<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A2: the setup steps after sign-up, and the sample store ("Cửa hàng mẫu"). A sample store is a business of its own
 * with fake data, so it never mixes with the real books; it is deleted as a whole after demo_expires_at.
 * Businesses that exist already count as set up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('setup_completed_at')->nullable()->after('logo_path');
            $table->boolean('is_demo')->default(false)->after('setup_completed_at');
            $table->string('demo_status', 20)->nullable()->after('is_demo'); // preparing | ready | failed
            $table->timestamp('demo_expires_at')->nullable()->after('demo_status');
            $table->index(['is_demo', 'demo_expires_at']);
        });

        DB::table('tenants')->update(['setup_completed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['is_demo', 'demo_expires_at']);
            $table->dropColumn(['setup_completed_at', 'is_demo', 'demo_status', 'demo_expires_at']);
        });
    }
};
