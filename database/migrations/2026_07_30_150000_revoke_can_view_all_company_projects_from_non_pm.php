<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canViewAllCompanyProjects is a project_manager-only default (see
// App\Services\RoleDefaultPermissions::MAP) — it's the single permission
// Api\User\ProjectMessengerController::isPM() and
// Api\User\ProjectCommentController::isInternalStaff() treat as "this person
// has PM/company-wide authority." A non-PM test account (first a Seller,
// now also a Developer) had it manually granted via Edit Permissions, which
// let them open the project-chat Create Group form (and see Seller in the
// participant picker) despite not actually being a Project Manager. Revokes
// it from every user whose role_type isn't 'project_manager'; never touches
// an actual PM's grant.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $nonPmIds = DB::table('users')->where('role_type', '!=', 'project_manager')->pluck('id');

        DB::table('user_company_permissions')
            ->whereIn('user_id', $nonPmIds)
            ->where('module_key', 'project_management')
            ->where('permission_key', 'canViewAllCompanyProjects')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale;
        // re-granting automatically would reopen the exact leak this closes.
    }
};
