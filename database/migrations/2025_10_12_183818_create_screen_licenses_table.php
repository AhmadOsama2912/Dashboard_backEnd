<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScreenLicensesTable extends Migration
{
    public function up()
    {
        Schema::create('screen_licenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('screen_id')->constrained()->cascadeOnDelete();
            $t->foreignId('enrollment_code_id')->nullable()->constrained('enrollment_codes')->nullOnDelete();

            // Use DATETIME to avoid MySQL default restrictions
            $t->dateTime('starts_at');
            $t->dateTime('expires_at');

            $t->string('status', 16)->default('active');
            $t->timestamps();

            $t->index(['screen_id', 'status']);
            $t->index('expires_at');
        });

    }

    public function down()
    {
        Schema::dropIfExists('screen_licenses');
    }
}
