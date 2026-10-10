<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 2026-09-04 request: a Developer (and equally a Designer, Production User or
// Team Member) may only VIEW the tasks handed to them — "a developer cannot
// assign or reassign a task to another developer... only view the task and its
// detail, cannot create either". These four permissions were defaults for all
// four roles, so every already-created worker still holds them; changing
// RoleDefaultPermissions::MAP alone would only have applied to users created
// from now on. This sweeps the existing ones.
//
// Deliberately NOT touched:
//   - canViewTasks — the whole point is that they keep seeing their tasks.
//   - Task status — progressing their own task to Done needs no permission at
//     all (TaskStatusService::canChangeTaskStatus()'s is_assignee path), so
//     nothing here can take that away.
//   - Deliverables/attachments/chat permissions — a separate feature set the
//     request didn't cover.
//   - project_manager and qa — both keep the full task bundle.
//
// Reassignment needed more than a revoke to actually close: Api\User\
// TaskController::update()'s $isOwnTask/$isTeamMember paths widen assigned_to
// past any permission, and a worker is virtually always a team member of the
// project their own task lives on. That's now a hard role check
// (TaskController::NON_REASSIGNING_ROLES), so re-granting canAssignTasks by
// hand to one of these roles no longer reopens reassignment either.
return new class extends Migration
{
    private const ROLES = ['developer', 'designer', 'production', 'team_member'];

    private const REVOKED_PERMS = [
        'canCreateTasks', 'canCreateLinkedProjectTask', 'canEditTasks', 'canAssignTasks',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $userIds = DB::table('users')->whereIn('role_type', self::ROLES)->pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        DB::table('user_company_permissions')
            ->whereIn('user_id', $userIds)
            ->where('module_key', 'project_management')
            ->whereIn('permission_key', self::REVOKED_PERMS)
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — same reasoning as
        // 2026_08_05_160000_revoke_all_task_permissions_from_sellers: which of
        // these keys a given user held before is not recorded anywhere, so a
        // blanket re-grant would hand out more than was taken. A Company Admin
        // can re-grant per user from Edit User if a specific worker really
        // does need task-management rights.
    }
};
