<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll', function (Blueprint $table) {
            // Separate from the existing generic `deductions` field (which
            // stays manual — loans, penalties, etc.) so the attendance-based
            // portion is visible/auditable on its own, auto-calculated from
            // that month's Attendance records but still editable pre-process.
            $table->decimal('attendance_deduction', 10, 2)->default(0)->after('deductions');
            $table->json('attendance_deduction_breakdown')->nullable()->after('attendance_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payroll', function (Blueprint $table) {
            $table->dropColumn(['attendance_deduction', 'attendance_deduction_breakdown']);
        });
    }
};
