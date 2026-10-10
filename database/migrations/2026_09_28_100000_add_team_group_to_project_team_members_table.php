<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Splits a project's team into two independent resource groups — Sales
// (Upseller/Seller/PM Sales) and Production (PM Design/PM Developer/PM SEO/
// plain team members) — so the Team Resources UI can show them as two
// separate sections instead of one flat list. Deliberately independent of
// role_in_project (project_manager/production_user/team_member/reviewer),
// which stays about "what this person does" — a "PM Sales" business
// designation still holds the Project Manager role and can still sit in
// role_in_project='project_manager', but belongs in the Sales group. Default
// 'production' matches every row that exists before this migration (a Seller
// could only ever join project_team_members from 2026-09-15 onward, and the
// very next migration backfills those rows to 'sales').
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_team_members', function (Blueprint $table) {
            $table->enum('team', ['sales', 'production'])->default('production')->after('role_in_project');
        });
    }

    public function down(): void
    {
        Schema::table('project_team_members', function (Blueprint $table) {
            $table->dropColumn('team');
        });
    }
};
