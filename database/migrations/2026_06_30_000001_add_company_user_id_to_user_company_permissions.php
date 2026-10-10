<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_company_permissions', function (Blueprint $table) {
            // Link permissions to the assignment row so they cascade-delete when an assignment is removed
            $table->foreignId('company_user_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('company_user_assignments')
                  ->onDelete('cascade');
            $table->index('company_user_id');
        });

        // Back-fill company_user_id for all existing permission rows
        DB::statement('
            UPDATE user_company_permissions p
            JOIN company_user_assignments a
              ON a.user_id = p.user_id AND a.company_id = p.company_id
            SET p.company_user_id = a.id
            WHERE p.company_user_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('user_company_permissions', function (Blueprint $table) {
            $table->dropForeign(['company_user_id']);
            $table->dropIndex(['company_user_id']);
            $table->dropColumn('company_user_id');
        });
    }
};
