<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A project's own Seller can carry a cosmetic 'project_manager' team row
// purely so the "Project Manager" column shows a name instead of
// "Unassigned" on a self-run project (see ProjectSellerAssignmentService
// and Api\User\ProjectController::store()/assignProjectManager()). Before
// today's fix, assignProjectManager() never removed this row once the
// Seller later assigned a REAL Project Manager to the project — leaving the
// Seller still listed as a team member (wrongly labeled "Project Manager")
// on Team/Resources views, even though someone else now actually runs the
// project. This one-time cleanup removes those now-stale rows for projects
// that already have a different, real project_manager_id set. Safe to
// re-run; matches nothing once caught up.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_team_members') || !Schema::hasTable('projects') || !Schema::hasTable('users')) {
            return;
        }

        $staleIds = DB::table('project_team_members as ptm')
            ->join('projects as p', 'p.id', '=', 'ptm.project_id')
            ->join('users as u', 'u.id', '=', 'ptm.user_id')
            ->where('u.role_type', 'seller')
            ->where('ptm.role_in_project', 'project_manager')
            ->whereColumn('p.seller_id', 'ptm.user_id')
            ->whereColumn('p.project_manager_id', '!=', 'ptm.user_id')
            ->whereNotNull('p.project_manager_id')
            ->pluck('ptm.id');

        if ($staleIds->isNotEmpty()) {
            DB::table('project_team_members')->whereIn('id', $staleIds)->delete();
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};
