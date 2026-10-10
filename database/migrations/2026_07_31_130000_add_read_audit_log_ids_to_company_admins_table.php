<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            if (!Schema::hasColumn('company_admins', 'read_audit_log_ids')) {
                $table->json('read_audit_log_ids')->nullable()->after('projects_last_read_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            if (Schema::hasColumn('company_admins', 'read_audit_log_ids')) {
                $table->dropColumn('read_audit_log_ids');
            }
        });
    }
};
