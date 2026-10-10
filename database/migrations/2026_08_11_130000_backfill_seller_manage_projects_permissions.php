<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // App\Services\RoleDefaultPermissions::MAP's Seller bucket now also
    // grants canManageProjectChatParticipants/canAddSellerToProjectChat
    // (cosmetic only — Api\User\ProjectMessengerController::isPM() hard-blocks
    // role_type='seller' regardless) and canEditProjects/canCompleteProjects/
    // canCloseProjects/canReopenProjects/canManageProjectInvoices (functional
    // — Api\User\ProjectController's visibleProjects() scope includes a
    // seller_id match, so these apply to a Seller's own linked project).
    // Backfill existing Sellers the same way new signups get it. Only ever
    // INSERTS a missing grant — never touches or removes an existing row.
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $permissionKeys = [
            'canManageProjectChatParticipants', 'canAddSellerToProjectChat',
            'canEditProjects', 'canCompleteProjects', 'canCloseProjects', 'canReopenProjects', 'canManageProjectInvoices',
        ];
        $now = now();

        $users = DB::table('users')->where('role_type', 'seller')->get(['id', 'company_id']);

        foreach ($users as $user) {
            $rows = [];
            foreach ($permissionKeys as $permKey) {
                $rows[] = [
                    'user_id'        => $user->id,
                    'company_id'     => $user->company_id,
                    'module_key'     => 'project_management',
                    'permission_key' => $permKey,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
            DB::table('user_company_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};
