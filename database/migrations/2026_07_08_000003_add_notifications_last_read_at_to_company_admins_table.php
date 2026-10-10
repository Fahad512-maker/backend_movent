<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            if (!Schema::hasColumn('company_admins', 'notifications_last_read_at')) {
                $table->timestamp('notifications_last_read_at')->nullable()->after('last_login_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            if (Schema::hasColumn('company_admins', 'notifications_last_read_at')) {
                $table->dropColumn('notifications_last_read_at');
            }
        });
    }
};
