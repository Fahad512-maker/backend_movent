<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A Seller must never be treated as PM/internal-staff-tier — but
// canViewAllCompanyProjects (a PM/Admin-delegate permission) had been
// manually granted to at least one Seller account via Edit Permissions.
// Api\User\ProjectController::isInternalCommentStaff() and several
// mention-candidate/task checks throughout the app treat anyone holding
// canViewAllCompanyProjects as internal staff — so a Seller with this one
// permission gets full-company task visibility AND sees Developer/Designer/
// QA/Production as @mention candidates in what should be their strictly
// client-facing comment tier. None of these four keys are ever a Seller
// default (see App\Services\RoleDefaultPermissions::MAP) — Sellers get their
// own canViewLinkedProjects instead. Revokes only from actual 'seller'
// role_type users; never touches these permissions for any other role.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $sellerIds = DB::table('users')->where('role_type', 'seller')->pluck('id');

        DB::table('user_company_permissions')
            ->whereIn('user_id', $sellerIds)
            ->where('module_key', 'project_management')
            ->whereIn('permission_key', ['canViewProjects', 'canViewAllCompanyProjects', 'canEditProjects', 'canAssignProjects'])
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale;
        // re-granting automatically would reopen the exact leak this closes.
    }
};
