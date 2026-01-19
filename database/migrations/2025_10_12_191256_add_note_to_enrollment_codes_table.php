<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('enrollment_codes', function (Blueprint $table) {
            // use text if notes can be long; string(190) if short labels
            $table->text('note')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_codes', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
