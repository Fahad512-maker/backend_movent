<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Second half of the user_company_roles backfill.
//
// 2026_09_14_100000 seeded roles from company_user_assignments, which covers
// every multi-company user. It misses a whole class of account that predates
// that table: users whose membership is recorded ONLY by the legacy
// users.company_id column, with no assignment row at all. On the database this
// was written against that was 11 of 22 users — half the roster — and every one
// of them would have come back role-less from User::rolesIn().
//
// Split into its own migration rather than folded into the first because the
// first has already run on some databases; a migration that has run does not
// run again, so the gap needed a new file.
//
// insertOrIgnore + the unique index means this is safe alongside the first
// migration and safe to re-run: a user covered there is skipped here.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_company_roles') || !Schema::hasTable('users')) {
            return;
        }

        DB::table('users')
            ->whereNull('deleted_at')
            ->whereNotNull('company_id')
            ->whereNotNull('role_type')
            ->where('role_type', '!=', '')
            ->select('id', 'company_id', 'role_type')
            ->orderBy('id')
            ->chunk(500, function ($users) {
                $now = now();

                DB::table('user_company_roles')->insertOrIgnore(
                    $users->map(fn ($u) => [
                        'user_id'    => $u->id,
                        'company_id' => $u->company_id,
                        'role'       => $u->role_type,
                        'is_primary' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        // Nothing to undo on its own — dropping the table (the first
        // migration's down()) removes these rows with everything else. Deleting
        // them here would also delete rows the first migration inserted, since
        // the two overlap wherever a user has both a company_id and a matching
        // assignment.
    }
};
