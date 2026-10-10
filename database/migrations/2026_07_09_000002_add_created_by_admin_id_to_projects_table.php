<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// projects.created_by only FKs to `users` — Company Admin (the only actor who
// can create a project today) has no `users` row, so it was always left null.
// Adds a parallel nullable FK to company_admins, mirroring the same
// admin/user dual-column pattern already used by project_attachments,
// project_task_attachments, and project_comments. Existing created_by column
// and data are untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'created_by_admin_id')) {
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('created_by');
                $table->foreign('created_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'created_by_admin_id')) {
                $table->dropForeign(['created_by_admin_id']);
                $table->dropColumn('created_by_admin_id');
            }
        });
    }
};
