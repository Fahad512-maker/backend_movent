<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The registration seat/company picker ("10/25/50/100/Unlimited users",
// "1/3/5/Unlimited companies") only ever fed the checkout price calculation —
// the chosen values were never persisted, so every admin fell back to their
// Package's max_users_per_company/max_companies, which are NULL (unlimited)
// on every package today. These per-admin columns let register() persist
// what the customer actually paid for; enforcement code prefers this value
// over the shared Package default when set.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            $table->unsignedInteger('max_users_per_company')->nullable()->after('package_id');
            $table->unsignedInteger('max_companies')->nullable()->after('max_users_per_company');
        });
    }

    public function down(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            $table->dropColumn(['max_users_per_company', 'max_companies']);
        });
    }
};
