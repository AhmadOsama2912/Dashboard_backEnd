<?php

// database/migrations/2025_10_13_000000_add_status_to_screens_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('screens', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('app_version');
        });
    }
    public function down(): void {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
