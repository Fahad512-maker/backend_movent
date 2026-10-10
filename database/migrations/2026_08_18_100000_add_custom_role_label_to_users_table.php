<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Backs "Custom Role" on the Add/Edit User pages: role_type stays one of the
// fixed structural buckets (users.role_type is a real ENUM — see
// 2024_01_01_000005_create_users_table.php and the two _role_type_enum
// widening migrations), since ~28 controllers key real behavior off exact
// role_type string comparisons, not just permission keys. custom_role_label
// is purely a DISPLAY override — when set, the UI shows this instead of the
// generic bucket label (e.g. "Marketing Lead" instead of "Team Member"),
// while every permission/visibility check still runs against the real
// role_type underneath.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('custom_role_label', 100)->nullable()->after('role_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('custom_role_label');
        });
    }
};
