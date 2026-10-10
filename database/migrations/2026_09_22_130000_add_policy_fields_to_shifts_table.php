<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedSmallInteger('grace_period_minutes')->default(15)->after('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0)->after('grace_period_minutes');
            // ISO weekday ints (1=Mon..7=Sun). Null falls back to the
            // company's attendance_policies.default_working_days.
            $table->json('working_days')->nullable()->after('break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['grace_period_minutes', 'break_minutes', 'working_days']);
        });
    }
};
