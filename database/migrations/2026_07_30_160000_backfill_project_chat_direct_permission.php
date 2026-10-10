<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canCreateProjectDirectChat is now also a default for
// production/developer/designer/qa/team_member (see
// App\Services\RoleDefaultPermissions::MAP) — they can start a direct chat
// with any project-eligible person EXCEPT a Seller (enforced in
// Api\User\ProjectMessengerController::createDirect() via blockedSellerIds(),
// since none of these roles hold canAddSellerToProjectChat). Backfills the
// grant for existing users of these role_types; only ever INSERTS a missing
// grant, same precedent as 2026_07_29_150000_backfill_project_attachment_permissions.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $roleTypes = ['production', 'developer', 'designer', 'qa', 'team_member'];
        $now = now();

        $users = DB::table('users')->whereIn('role_type', $roleTypes)->get(['id', 'company_id']);

        $rows = $users->map(fn ($user) => [
            'user_id'        => $user->id,
            'company_id'     => $user->company_id,
            'module_key'     => 'project_management',
            'permission_key' => 'canCreateProjectDirectChat',
            'created_at'     => $now,
            'updated_at'     => $now,
        ])->all();

        if (!empty($rows)) {
            DB::table('user_company_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale.
    }
};
