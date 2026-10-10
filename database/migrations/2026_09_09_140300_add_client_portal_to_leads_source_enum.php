<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A Lead raised by a client asking for a service from their own portal.
//
// Its own source value rather than reusing 'website' or 'other': these are
// the warmest leads a company gets — an existing paying client asking for more
// work — and lumping them in with cold website enquiries would hide exactly
// the ones the sales team should call first.
//
// Raw ALTER because the column is a MySQL enum; Laravel's schema builder can't
// extend one in place, and the existing enum migrations in this project
// (e.g. 2026_09_07_160000_add_admin_to_users_role_type_enum) do the same.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE leads MODIFY COLUMN source
             ENUM('website','referral','cold_call','social','event','other','client_portal') NULL"
        );
    }

    public function down(): void
    {
        // Anything already recorded as client_portal has to go somewhere that
        // still exists, or the ALTER below silently blanks it.
        DB::table('leads')->where('source', 'client_portal')->update(['source' => 'other']);

        DB::statement(
            "ALTER TABLE leads MODIFY COLUMN source
             ENUM('website','referral','cold_call','social','event','other') NULL"
        );
    }
};
