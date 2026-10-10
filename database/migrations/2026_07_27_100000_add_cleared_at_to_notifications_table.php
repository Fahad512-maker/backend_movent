<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Soft-clear for the staff notification bell — a cleared notification stays
// in the table (audit/history preserved) but is excluded from the visible
// list/unread-count, mirroring how is_read/read_at already work.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'cleared_at')) {
                $table->timestamp('cleared_at')->nullable()->after('read_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'cleared_at')) {
                $table->dropColumn('cleared_at');
            }
        });
    }
};
