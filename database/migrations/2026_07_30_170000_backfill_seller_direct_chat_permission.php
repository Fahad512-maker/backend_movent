<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canCreateProjectDirectChat is now also a Seller default (see
// App\Services\RoleDefaultPermissions::MAP) — lets a Seller start a NEW
// direct chat, but only ever targeting Company Admin or the project's actual
// Project Manager (createDirect()/eligibleParticipants() both hard-restrict
// the target, never Developer/Designer/QA/Production/Team Member). Backfills
// the grant for existing Seller users; only ever INSERTS a missing grant,
// same precedent as 2026_07_29_150000_backfill_project_attachment_permissions.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $now = now();
        $sellers = DB::table('users')->where('role_type', 'seller')->get(['id', 'company_id']);

        $rows = $sellers->map(fn ($user) => [
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
