<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('username', 190);
            $table->string('email', 190);
            $table->string('password');

            $table->enum('role', ['manager','supervisor'])->index();
            $table->string('phone', 50)->nullable();
            $table->rememberToken();

            $table->timestamp('last_login_at')->nullable()->index();
            $table->ipAddress('last_login_ip')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unique per customer
            $table->unique(['customer_id','email']);
            $table->unique(['customer_id','username']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('users');
    }
};
