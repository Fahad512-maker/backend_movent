<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expected daily arrival/departure time on the employee's own profile —
// distinct from attendance.check_in/check_out, which are that day's ACTUAL
// times. This is the schedule an employee is expected to keep; attendance
// records how they actually did against it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->time('shift_start_time')->nullable()->after('employment_type');
            $table->time('shift_end_time')->nullable()->after('shift_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['shift_start_time', 'shift_end_time']);
        });
    }
};
