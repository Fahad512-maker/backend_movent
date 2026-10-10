<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Reverts the 2026-08-13 default grant (App\Services\RoleDefaultPermissions::MAP)
// and the 2026-08-14 backfill (backfill_pm_task_view_permissions) for these two
// specific keys — canViewTasks from that same backfill is untouched and stays
// granted. canViewAllCompanyProjects let a Project Manager's
// Api\User\ProjectMessengerController::isPM() check treat them as PM-tier on
// EVERY company project, not just their own — bypassing the Seller's "Invite
// Project Manager into Project Chat" feature entirely (nothing to invite into
// if the PM already had unconditional access) and letting them manage chat
// Participants on projects they weren't actually assigned to. Removes both
// permission rows from every existing project_manager account; new PM users
// no longer get either by default (see RoleDefaultPermissions::MAP).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $pmIds = DB::table('users')->where('role_type', 'project_manager')->pluck('id');

        DB::table('user_company_permissions')
            ->whereIn('user_id', $pmIds)
            ->where('module_key', 'project_management')
            ->whereIn('permission_key', ['canViewAllCompanyProjects', 'canManageProjectChatParticipants'])
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_150000's rationale;
        // re-granting automatically would reopen the exact leak this closes.
    }
};
