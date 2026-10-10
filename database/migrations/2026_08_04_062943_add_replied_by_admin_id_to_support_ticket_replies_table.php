<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('support_ticket_replies', function (Blueprint $table) {
            // Company Admin has no `users` row — same dual-actor pattern as
            // project_comments (author_admin_id/author_user_id).
            $table->unsignedBigInteger('replied_by_admin_id')->nullable()->after('replied_by');
            $table->foreign('replied_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_ticket_replies', function (Blueprint $table) {
            $table->dropForeign(['replied_by_admin_id']);
            $table->dropColumn('replied_by_admin_id');
        });
    }
};
