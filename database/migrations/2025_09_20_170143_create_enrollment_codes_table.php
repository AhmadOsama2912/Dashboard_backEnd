<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('enrollment_codes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $t->string('code', 32)->unique();   // مثال: ABC123
            $t->unsignedInteger('max_uses')->default(1);
            $t->unsignedInteger('used_count')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['customer_id','expires_at']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollment_codes');
    }
};
