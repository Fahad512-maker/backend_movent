<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Personal profile-photo avatar — distinct from logo_path, which is the
// shared tenant-wide BUSINESS logo (Settings > Company tab, used on
// invoices). Mirrors users.avatar_path (already exists there, just unused).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            if (!Schema::hasColumn('company_admins', 'avatar_path')) {
                $table->string('avatar_path', 600)->nullable()->after('logo_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            if (Schema::hasColumn('company_admins', 'avatar_path')) {
                $table->dropColumn('avatar_path');
            }
        });
    }
};
