<?php

// database/migrations/2025_10_12_000001_add_contact_fields_to_customers_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'email')) {
                $table->string('email', 190)->unique()->after('name');
            }
            if (!Schema::hasColumn('customers', 'phone')) {
                $table->string('phone', 50)->nullable()->after('email');
            }
            if (!Schema::hasColumn('customers', 'note')) {
                $table->string('note', 500)->nullable()->after('phone');
            }
            // If your original table didn’t have these either, keep them here for safety
            if (!Schema::hasColumn('customers', 'logo')) {
                $table->string('logo', 255)->nullable()->after('package_id');
            }
            if (!Schema::hasColumn('customers', 'meta')) {
                $table->json('meta')->nullable()->after('logo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'meta'))  $table->dropColumn('meta');
            if (Schema::hasColumn('customers', 'logo'))  $table->dropColumn('logo');
            if (Schema::hasColumn('customers', 'note'))  $table->dropColumn('note');
            if (Schema::hasColumn('customers', 'phone')) $table->dropColumn('phone');
            if (Schema::hasColumn('customers', 'email')) $table->dropColumn('email'); // drops unique with column
        });
    }
};
