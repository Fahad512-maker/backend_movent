<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Adds the new role-based-default-permissions roles (Add/Edit User role
    // selection) to the existing role_type enum. Purely additive — every
    // existing value is kept so current users' role_type rows stay valid;
    // this only widens what can be picked going forward.
    public function up(): void
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

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'role_type')) {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role_type ENUM(
            'seller','client','hr','finance','project_manager','production',
            'invoice_admin','invoice_manager','invoice_creator','invoice_viewer','payment_manager'
        ) NOT NULL DEFAULT 'invoice_viewer'");
    }
};
