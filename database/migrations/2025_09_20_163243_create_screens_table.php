<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('screens', function (Blueprint $t) {
      $t->bigIncrements('id');

      // لمن تنتمي الشاشة؟
      $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

      // تعريف فريد لكل جهاز داخل نفس الشركة
      $t->string('serial_number', 190)->index();
      $t->unique(['customer_id','serial_number']);

      // وضع الوصول: company = كل المشرفين في الشركة / user = مشرف محدد
      $t->enum('access_scope', ['company','user'])->default('company');
      $t->foreignId('assigned_user_id')->nullable()
        ->constrained('users')->nullOnDelete()->index();

      // معلومات الجهاز وحالته
      $t->string('device_model', 190)->nullable();
      $t->string('os_version', 190)->nullable();
      $t->string('app_version', 190)->nullable();

      $t->timestamp('activated_at')->nullable();
      $t->timestamp('last_check_in_at')->nullable()->index();

      // توكن الجهاز لنداءات API اللاحقة
      $t->string('api_token', 80)->unique()->nullable();

      $t->json('meta')->nullable();
      $t->timestamps();
    });
  }

  public function down(): void {
    Schema::dropIfExists('screens');
  }
};
