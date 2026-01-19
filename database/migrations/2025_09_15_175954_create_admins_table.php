<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Basic identity
            $table->string('name', 190)->nullable();
            $table->string('username', 190)->unique();
            $table->string('email', 190)->unique();
            $table->timestamp('email_verified_at')->nullable();

            // Auth
            $table->string('password');
            $table->rememberToken();

            // Optional profile/ops
            $table->string('phone', 50)->nullable();
            $table->string('avatar_path', 255)->nullable();

            // Status & security
            // $table->enum('status', ['active', 'suspended', 'disabled'])
            //       ->default('active')
            //       ->index();
            // 2FA placeholders (adjust types if you use Laravel Fortify/Passport/etc.)
            // $table->text('two_factor_secret')->nullable();
            // $table->text('two_factor_recovery_codes')->nullable();

            // Observability
            $table->timestamp('last_login_at')->nullable()->index();
            $table->ipAddress('last_login_ip')->nullable();

            // Extensibility
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes(); // adds deleted_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
