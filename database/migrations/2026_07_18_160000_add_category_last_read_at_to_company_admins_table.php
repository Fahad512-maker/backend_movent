<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            if (!Schema::hasColumn('company_admins', 'tasks_last_read_at')) {
                $table->timestamp('tasks_last_read_at')->nullable()->after('notifications_last_read_at');
            }
            if (!Schema::hasColumn('company_admins', 'projects_last_read_at')) {
                $table->timestamp('projects_last_read_at')->nullable()->after('tasks_last_read_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            if (Schema::hasColumn('company_admins', 'projects_last_read_at')) {
                $table->dropColumn('projects_last_read_at');
            }
            if (Schema::hasColumn('company_admins', 'tasks_last_read_at')) {
                $table->dropColumn('tasks_last_read_at');
            }
        });
    }
};
