<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive enum widen — keeps all 5 existing values, adds the 2 new
        // statuses the calculator can produce (Leave/Off Day). Raw statement
        // since Laravel's schema builder has no enum-modify helper; this app
        // runs MySQL (confirmed via .env DB_CONNECTION).
        DB::statement("ALTER TABLE attendance MODIFY status ENUM('present','absent','late','half_day','holiday','leave','off_day') DEFAULT 'present'");

        Schema::table('attendance', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('employee_id')->constrained('companies')->nullOnDelete();
            // Snapshots below are captured at calculation time so a later
            // shift edit, rename, or employee reassignment never rewrites
            // already-saved historical attendance rows.
            $table->string('department', 100)->nullable()->after('date');
            $table->foreignId('shift_id')->nullable()->after('department')->constrained('shifts')->nullOnDelete();
            $table->string('shift_name', 100)->nullable()->after('shift_id');
            $table->time('scheduled_check_in')->nullable()->after('shift_name');
            $table->time('scheduled_check_out')->nullable()->after('scheduled_check_in');
            $table->unsignedSmallInteger('grace_period_minutes')->nullable()->after('scheduled_check_out');
            $table->unsignedSmallInteger('break_minutes')->nullable()->after('grace_period_minutes');
            $table->unsignedInteger('late_by_minutes')->nullable()->after('check_out');
            $table->unsignedInteger('early_leave_by_minutes')->nullable()->after('late_by_minutes');
            // Null (not 0) when checkout is missing — never fabricate hours.
            $table->unsignedInteger('worked_minutes')->nullable()->after('early_leave_by_minutes');
            $table->unsignedInteger('overtime_minutes')->nullable()->after('worked_minutes');
            $table->boolean('is_missing_checkout')->default(false)->after('overtime_minutes');
            $table->string('source', 20)->default('system')->after('is_missing_checkout');
            $table->boolean('is_manual_override')->default(false)->after('source');
            $table->timestamp('calculated_at')->nullable()->after('is_manual_override');

            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'date']);
            $table->dropIndex(['company_id', 'date']);
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn([
                'department', 'shift_name', 'scheduled_check_in', 'scheduled_check_out',
                'grace_period_minutes', 'break_minutes', 'late_by_minutes', 'early_leave_by_minutes',
                'worked_minutes', 'overtime_minutes', 'is_missing_checkout', 'source',
                'is_manual_override', 'calculated_at',
            ]);
        });

        DB::statement("ALTER TABLE attendance MODIFY status ENUM('present','absent','late','half_day','holiday') DEFAULT 'present'");
    }
};
