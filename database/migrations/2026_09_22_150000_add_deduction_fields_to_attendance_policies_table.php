<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table) {
            // % of one day's pay (monthly salary / working days in month)
            // deducted per occurrence beyond the free allowance below.
            $table->decimal('late_deduction_percent', 5, 2)->default(0)->after('default_working_days');
            $table->decimal('half_day_deduction_percent', 5, 2)->default(50)->after('late_deduction_percent');
            $table->decimal('absent_deduction_percent', 5, 2)->default(100)->after('half_day_deduction_percent');
            // Off Day is a non-working day (weekend) so this defaults to 0 —
            // exists purely as a company-configurable override for unusual
            // policies, not something most companies will ever set non-zero.
            $table->decimal('off_day_deduction_percent', 5, 2)->default(0)->after('absent_deduction_percent');

            // Free occurrences per month before deduction starts applying.
            $table->unsignedSmallInteger('free_late_allowed')->default(0)->after('off_day_deduction_percent');
            $table->unsignedSmallInteger('free_half_day_allowed')->default(0)->after('free_late_allowed');
            $table->unsignedSmallInteger('free_absent_allowed')->default(0)->after('free_half_day_allowed');
            $table->unsignedSmallInteger('free_off_day_allowed')->default(0)->after('free_absent_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->dropColumn([
                'late_deduction_percent', 'half_day_deduction_percent',
                'absent_deduction_percent', 'off_day_deduction_percent',
                'free_late_allowed', 'free_half_day_allowed',
                'free_absent_allowed', 'free_off_day_allowed',
            ]);
        });
    }
};
