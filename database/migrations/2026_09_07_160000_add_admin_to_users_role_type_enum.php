<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Adds the "Admin" role — a staff member who maintains the company's Brands
// (see App\Models\Brand): view, add, edit and delete, and nothing else by
// default. Distinct from 'company_admin', which is the tenant owner's own
// all-permissions bucket.
//
// Only a Company Admin may ever assign it: it is absent from
// Api\User\UserManagementController::VALID_ROLES and filtered out of the role
// list that controller serves, so a delegated User Manager neither sees it
// nor can send it.
//
// Same additive pattern as 2026_08_06_140000_add_lead_manager_to_users_role_
// type_enum: every existing value is kept, and the column is inspected first
// so a re-run is a no-op rather than an error.
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
        if ($column && str_contains($column->type, "'admin'")) {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role_type ENUM(
            'seller','client','hr','finance','project_manager','production',
            'invoice_admin','invoice_manager','invoice_creator','invoice_viewer','payment_manager',
            'developer','designer','qa','team_member','viewer','compliance','invoice_user','company_admin',
            'lead_manager','admin'
        ) NOT NULL DEFAULT 'invoice_viewer'");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'role_type')) {
            return;
        }

        // Anyone still on the role being removed falls back to the generic
        // least-privilege bucket — their brand permissions are permission
        // rows and survive independently of role_type, which is descriptive.
        DB::statement("UPDATE users SET role_type = 'team_member' WHERE role_type = 'admin'");

        DB::statement("ALTER TABLE users MODIFY COLUMN role_type ENUM(
            'seller','client','hr','finance','project_manager','production',
            'invoice_admin','invoice_manager','invoice_creator','invoice_viewer','payment_manager',
            'developer','designer','qa','team_member','viewer','compliance','invoice_user','company_admin',
            'lead_manager'
        ) NOT NULL DEFAULT 'invoice_viewer'");
    }
};
