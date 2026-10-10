<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Descriptive-only field (like users.role_type) — a Company Admin can label
// a module grant as "Own records / Assigned / All company / View only / No
// access" for clarity. Never read by any module's query logic; enforcement
// stays 100% on the existing per-permission-key checks.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_company_permissions', function (Blueprint $table) {
            if (!Schema::hasColumn('user_company_permissions', 'data_scope')) {
                $table->string('data_scope', 20)->nullable()->after('permission_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_company_permissions', function (Blueprint $table) {
            if (Schema::hasColumn('user_company_permissions', 'data_scope')) {
                $table->dropColumn('data_scope');
            }
        });
    }
};
