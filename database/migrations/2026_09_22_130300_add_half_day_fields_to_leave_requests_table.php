<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('is_half_day')->default(false)->after('total_days');
            $table->string('half_day_period', 10)->nullable()->after('is_half_day');
        });

        // Widen tinyInteger -> decimal(4,1) so half-day leave can store 0.5
        // days. Additive/widening only — existing integer values remain
        // valid. Raw statement (no doctrine/dbal ->change() dependency),
        // same approach as the attendance.status enum widen above.
        DB::statement('ALTER TABLE leave_requests MODIFY total_days DECIMAL(4,1) UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE leave_requests MODIFY total_days TINYINT UNSIGNED NULL');

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['is_half_day', 'half_day_period']);
        });
    }
};
