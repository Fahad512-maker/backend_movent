<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Marks the moment the Super Admin set their own password from the console.
//
// Until then, login accepts the SUPER_ADMIN_PASSWORD env value (that is how a
// fresh install signs in for the first time) and syncs it onto the row. Once
// this stamp is set, Api\Auth\SuperAdminAuthController stops honouring the env
// password entirely — without that, the next login with the old env value
// would silently overwrite the password the admin just chose.
//
// Recovery, if the chosen password is lost: set this column back to NULL and
// the env password works again.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('super_admins') || Schema::hasColumn('super_admins', 'password_changed_at')) {
            return;
        }

        Schema::table('super_admins', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('super_admins') || !Schema::hasColumn('super_admins', 'password_changed_at')) {
            return;
        }

        Schema::table('super_admins', function (Blueprint $table) {
            $table->dropColumn('password_changed_at');
        });
    }
};
