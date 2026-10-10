<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The roles a user holds, PER COMPANY. Replaces the single users.role_type
// column as the source of truth for "what is this person here".
//
// Two limitations of role_type this fixes:
//   1. One role per user. A person who sells AND manages projects had to be
//      given one of the two and hand-granted the other's permissions.
//   2. One role per PERSON, not per company. A user active in three companies
//      carried the same role_type into all three, so a Seller in company A was
//      a Seller in company B whether or not anyone intended it.
//
// users.role_type is deliberately NOT dropped here. ~139 runtime branches and
// ~174 frontend references still read it, so it stays as the PRIMARY role
// (is_primary below) and the two are kept in step by the controllers. Removing
// it is a later migration, once those call sites read rolesIn() instead.
//
// Permissions are unaffected by this table: user_company_permissions is still
// the only thing any gate reads. Roles only decide which DEFAULTS get seeded
// (RoleDefaultPermissions::forRoles(), a plain union), so holding two roles can
// only ever add grants — one role can never revoke what another gave.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_company_roles')) {
            Schema::create('user_company_roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('role', 50);
                // Exactly one row per (user, company) carries this. It is what
                // users.role_type mirrors, and what the UI shows as the
                // headline role while the other rows read as extras.
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->unique(['user_id', 'company_id', 'role'], 'user_company_roles_unique');
                $table->index(['user_id', 'company_id']);
            });
        }

        // Backfill: every existing membership keeps exactly the role it has
        // today, so this migration changes nobody's access. Suspended
        // assignments are included on purpose — suspension is reversible, and
        // dropping the role here would silently blank it on reactivation.
        //
        // insertOrIgnore + the unique index makes this safe to re-run, and
        // whereNotNull('role_type') skips rows a partial seed left blank.
        if (Schema::hasTable('company_user_assignments') && Schema::hasTable('users')) {
            DB::table('company_user_assignments AS a')
                ->join('users AS u', 'u.id', '=', 'a.user_id')
                ->whereNotNull('u.role_type')
                ->where('u.role_type', '!=', '')
                ->whereNull('u.deleted_at')
                ->select('a.user_id', 'a.company_id', 'u.role_type')
                ->orderBy('a.id')
                ->chunk(500, function ($rows) {
                    $now = now();

                    DB::table('user_company_roles')->insertOrIgnore(
                        $rows->map(fn ($r) => [
                            'user_id'    => $r->user_id,
                            'company_id' => $r->company_id,
                            'role'       => $r->role_type,
                            'is_primary' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all()
                    );
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_company_roles');
    }
};
