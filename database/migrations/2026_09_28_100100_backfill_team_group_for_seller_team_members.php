<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One-time backfill for the `team` column added by the previous migration.
// Every project_team_members row whose user holds the Seller role — in the
// company the row's own project belongs to — is moved from the 'production'
// default to 'sales'. Mirrors User::scopeHoldingRole()'s own fallback rule
// (a company-scoped user_company_roles row if one exists, otherwise the
// legacy users.role_type only when this company has no role rows for them
// yet) rather than re-deriving a second copy of that logic.
//
// Safe to re-run: it only ever moves a row from 'production' to 'sales', and
// nothing here still holds 'production' that this query would match twice.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_team_members') || !Schema::hasTable('projects') || !Schema::hasTable('users')) {
            return;
        }

        $sellerViaCompanyRole = DB::table('project_team_members as ptm')
            ->join('projects as p', 'p.id', '=', 'ptm.project_id')
            ->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('user_company_roles as ucr')
                    ->whereColumn('ucr.user_id', 'ptm.user_id')
                    ->whereColumn('ucr.company_id', 'p.company_id')
                    ->where('ucr.role', 'seller');
            })
            ->pluck('ptm.id');

        $sellerViaLegacyRoleType = DB::table('project_team_members as ptm')
            ->join('projects as p', 'p.id', '=', 'ptm.project_id')
            ->join('users as u', 'u.id', '=', 'ptm.user_id')
            ->where('u.role_type', 'seller')
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('user_company_roles as ucr')
                    ->whereColumn('ucr.user_id', 'ptm.user_id')
                    ->whereColumn('ucr.company_id', 'p.company_id');
            })
            ->pluck('ptm.id');

        $ids = $sellerViaCompanyRole->merge($sellerViaLegacyRoleType)->unique()->values();

        $ids->chunk(500)->each(function ($chunk) {
            DB::table('project_team_members')->whereIn('id', $chunk)->update(['team' => 'sales']);
        });
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_08_22_120000_backfill_seller_team_row_after_pm_assigned.php.
    }
};
