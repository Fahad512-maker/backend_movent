<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            // Raw check-out minus check-in, before break is subtracted —
            // distinct from worked_minutes (net, after break). Lets HR see
            // total time on-site vs. actual working time.
            $table->unsignedInteger('gross_minutes')->nullable()->after('check_out');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('gross_minutes');
        });
    }
};
