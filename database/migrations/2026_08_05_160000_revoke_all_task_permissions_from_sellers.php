<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Completes 2026_07_30_130000_revoke_seller_task_creation_permission's
// unfinished job — that migration only ever stripped
// canCreateLinkedProjectTask, but RoleDefaultPermissions::MAP kept
// (re-)granting it to every newly created Seller since the MAP itself was
// never actually fixed to match its own stated intent ("Sellers must have
// zero Task visibility/creation ability"), and some Seller accounts also
// picked up other task permissions via manual Company Admin edits. The Task
// feature (list/create/edit/attachments — not Production Queue or Reports,
// which are separate features) is now retired for this role entirely and
// hard-blocked server-side in Api\User\TaskController regardless of any of
// these; this sweeps every already-created Seller clean of them too.
return new class extends Migration
{
    private const TASK_PERMS = [
        'canViewTasks', 'canCreateTasks', 'canEditTasks', 'canAssignTasks', 'canMarkTaskBlocked',
        'canCreateLinkedProjectTask', 'canCompleteTasks', 'canReopenTasks', 'canOverrideTaskStatus',
        'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments', 'canDeleteTaskAttachments',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $sellerIds = DB::table('users')->where('role_type', 'seller')->pluck('id');

        DB::table('user_company_permissions')
            ->whereIn('user_id', $sellerIds)
            ->where('module_key', 'project_management')
            ->whereIn('permission_key', self::TASK_PERMS)
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale.
    }
};
