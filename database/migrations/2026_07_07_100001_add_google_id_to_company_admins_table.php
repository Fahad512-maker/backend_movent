<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            $table->string('google_id', 255)->nullable()->unique()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('company_admins', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });
    }
};
