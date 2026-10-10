<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// USD is now the system's only supported currency — Api\Admin\ClientController
// no longer accepts a 'currency' choice on company create/edit (it's hardcoded
// to 'USD' on create, and left untouched on edit). This column's own default
// was still 'PKR' from day one, so mirror
// 2026_08_16_100000_change_company_admins_currency_default_to_usd.php's fix
// for the sibling company_admins.currency column. Raw SQL, not
// Schema::table()->change(), since doctrine/dbal isn't installed in this
// project. Deliberately does NOT touch any existing row's value — a company
// already invoiced in PKR keeps that as its real historical currency.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies') || !Schema::hasColumn('companies', 'currency')) {
            return;
        }

        DB::statement("ALTER TABLE companies MODIFY currency VARCHAR(10) NOT NULL DEFAULT 'USD'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('companies') || !Schema::hasColumn('companies', 'currency')) {
            return;
        }

        DB::statement("ALTER TABLE companies MODIFY currency VARCHAR(10) NOT NULL DEFAULT 'PKR'");
    }
};
