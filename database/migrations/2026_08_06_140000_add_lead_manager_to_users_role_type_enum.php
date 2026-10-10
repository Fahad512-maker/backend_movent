<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Adds the new "Lead Manager" role (manages leads and assigns/transfers
// them to Sellers) to the existing role_type enum — same additive pattern
// as 2026_07_18_150000_add_new_roles_to_users_role_type_enum. Purely
// additive; every existing value is kept so current users' role_type rows
// stay valid, this only widens what can be picked going forward. Checks
// the current column definition first so re-running this migration (or
// running it after role_type has already been widened by hand) is a no-op
// rather than an error.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'role_type')) {
            return;
        }

        $column = DB::selectOne(
            "SELECT COLUMN_TYPE AS type FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role_type'"
        );
        if ($column && str_contains($column->type, "'lead_manager'")) {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role_type ENUM(
            'seller','client','hr','finance','project_manager','production',
            'invoice_admin','invoice_manager','invoice_creator','invoice_viewer','payment_manager',
            'developer','designer','qa','team_member','viewer','compliance','invoice_user','company_admin',
            'lead_manager'
        ) NOT NULL DEFAULT 'invoice_viewer'");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'role_type')) {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role_type ENUM(
            'seller','client','hr','finance','project_manager','production',
            'invoice_admin','invoice_manager','invoice_creator','invoice_viewer','payment_manager',
            'developer','designer','qa','team_member','viewer','compliance','invoice_user','company_admin'
        ) NOT NULL DEFAULT 'invoice_viewer'");
    }
};
