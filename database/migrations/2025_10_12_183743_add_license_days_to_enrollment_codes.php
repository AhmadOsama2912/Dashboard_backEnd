<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLicenseDaysToEnrollmentCodes extends Migration
{
    public function up()
    {
        Schema::table('enrollment_codes', function (Blueprint $t) {
            // Number of days to grant when a screen registers with this code
            $t->unsignedInteger('license_days')->nullable()->after('code'); // e.g. 30, 365
        });
    }

    public function down()
    {
        Schema::table('enrollment_codes', function (Blueprint $t) {
            $t->dropColumn('license_days');
        });
    }
}
