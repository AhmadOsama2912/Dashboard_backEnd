<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('packages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 190)->unique();
            $table->unsignedInteger('screens_limit')->default(0);
            $table->unsignedInteger('managers_limit')->default(0);
            $table->unsignedInteger('supervisors_limit')->default(0);
            $table->unsignedInteger('branches_limit')->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->text('support_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void {
        Schema::dropIfExists('packages');
    }
};
