<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 190);
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->string('logo', 255)->nullable(); // file path or URL
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
        });
    }
    public function down(): void {
        Schema::dropIfExists('customers');
    }
};
