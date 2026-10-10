<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ledger for App\Services\PermissionRolloutService — records which
// (role_type, module_key, permission_key) role-default has already been
// rolled out to existing users.
//
// Why it exists: role defaults live in RoleDefaultPermissions::MAP, but were
// only ever copied into user_company_permissions when a user was CREATED, so
// a permission added for a new feature reached new users and silently missed
// every existing one — the reason ~47 hand-written backfill migrations exist
// in this folder. This table lets a single sync command replace all of them.
//
// Deliberately seeded with NOTHING: an empty ledger makes the first sync
// treat every current default as not-yet-rolled-out, which is what fills the
// gaps that already exist today. After that first run the ledger is what
// keeps sync quiet on later deploys — and, critically, what stops it from
// re-granting a permission a Company Admin deliberately removed from a user.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permission_rollouts')) {
            return;
        }

        Schema::create('permission_rollouts', function (Blueprint $table) {
            $table->id();
            $table->string('role_type', 50);
            $table->string('module_key', 50);
            $table->string('permission_key', 100);
            $table->timestamp('rolled_out_at')->nullable();

            $table->unique(['role_type', 'module_key', 'permission_key'], 'permission_rollouts_triple_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_rollouts');
    }
};
