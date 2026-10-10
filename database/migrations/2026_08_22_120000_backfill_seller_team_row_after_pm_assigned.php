<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Api\User\ProjectController::assignProjectManager() used to DELETE the
// Seller's cosmetic 'project_manager' team row once a real Project Manager
// took over, instead of just downgrading its role_in_project — dropping the
// Seller off every Team/Resources view for that project entirely, even
// though they're still its Seller. Today's fix downgrades the row instead
// of deleting it, but that only helps going forward — this one-time
// backfill restores a team_member row (memberRoleLabel() on every
// Team/Resources page already prefers the user's actual role_type over this
// column, so it still displays correctly as "Seller") for any project
// that already lost it under the old behavior. Safe to re-run; matches
// nothing once caught up.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_team_members') || !Schema::hasTable('projects')) {
            return;
        }

        $affected = DB::table('projects as p')
            ->whereNotNull('p.seller_id')
            ->whereNotNull('p.project_manager_id')
            ->whereColumn('p.project_manager_id', '!=', 'p.seller_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('project_team_members as ptm')
                    ->whereColumn('ptm.project_id', 'p.id')
                    ->whereColumn('ptm.user_id', 'p.seller_id');
            })
            ->get(['p.id as project_id', 'p.seller_id']);

        if ($affected->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $affected->map(fn ($p) => [
            'project_id'      => $p->project_id,
            'user_id'         => $p->seller_id,
            'role_in_project' => 'team_member',
            'assigned_by'     => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ])->all();

        DB::table('project_team_members')->insert($rows);
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};
