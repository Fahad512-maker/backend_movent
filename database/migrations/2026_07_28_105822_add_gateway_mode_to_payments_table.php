<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Records whether an online-gateway payment ran through the company's
    // sandbox/test or live credentials at the time it was taken — the
    // company can change that mode later, so this must be captured per
    // payment rather than re-derived from the gateway's current config.
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'gateway_mode')) {
                $table->string('gateway_mode', 20)->nullable()->after('gateway_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'gateway_mode')) {
                $table->dropColumn('gateway_mode');
            }
        });
    }
};
