<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A1: what the plans list shows besides prices and limits (in the app and through the public plans API). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
            $table->string('badge', 40)->nullable()->after('description');
            $table->json('highlights')->nullable()->after('badge');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['description', 'badge', 'highlights']);
        });
    }
};
