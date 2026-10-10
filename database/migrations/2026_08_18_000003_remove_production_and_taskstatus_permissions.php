<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Retires 8 permission keys made obsolete by the Task-status simplification:
// the 4 Production Queue keys (feature removed entirely) and 4 per-hop
// task-status keys that only ever gated steps in the old guided pipeline —
// TaskStatusService::canChangeTaskStatus() is now a single free-jump check
// (Developer/Team Member on their own task, QA, PM, Company Admin via
// canOverrideTaskStatus), with no reader left for any of these 8 anywhere in
// the app. Irreversible — matches this codebase's existing convention for
// permission-cleanup migrations (e.g. 2026_08_13_100000_revoke_delete_any_
// project_chat_message_permission.php).
return new class extends Migration
{
    private const RETIRED_KEYS = [
        'canViewProductionQueue', 'canAssignProductionTasks', 'canStartProductionTasks', 'canSubmitProductionTasks',
        'canMarkTaskBlocked', 'canVerifyDeliverables', 'canCompleteTasks', 'canReopenTasks',
    ];

    public function up(): void
    {
        DB::table('user_company_permissions')
            ->where('module_key', 'project_management')
            ->whereIn('permission_key', self::RETIRED_KEYS)
            ->delete();
    }

    public function down(): void
    {
        // Irreversible — any company's manual customization of these grants
        // (away from role defaults) is not reconstructable, same as the
        // precedent migrations this mirrors.
    }
};
