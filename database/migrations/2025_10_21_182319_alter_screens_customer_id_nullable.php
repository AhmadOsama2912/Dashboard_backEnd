<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            // Drop FK first if it exists (adjust the name if different)
            try { $table->dropForeign(['customer_id']); } catch (\Throwable $e) {}

            // Make nullable
            $table->unsignedBigInteger('customer_id')->nullable()->change();

            // Recreate FK as nullable + nullOnDelete (optional but recommended)
            try {
                $table->foreign('customer_id')
                      ->references('id')->on('customers')
                      ->nullOnDelete()
                      ->cascadeOnUpdate();
            } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            try { $table->dropForeign(['customer_id']); } catch (\Throwable $e) {}
            // Revert to NOT NULL (only do this if you’re sure all rows have a customer)
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            try {
                $table->foreign('customer_id')
                      ->references('id')->on('customers')
                      ->restrictOnDelete()
                      ->cascadeOnUpdate();
            } catch (\Throwable $e) {}
        });
    }
};
