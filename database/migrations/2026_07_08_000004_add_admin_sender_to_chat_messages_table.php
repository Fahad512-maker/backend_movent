<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Company Admin has no `users` row, so chat_messages.sender_id (NOT NULL, FK'd
// only to `users`) could never represent an admin-authored message — Team Chat
// was read-only for admins for this reason. Made sender_id nullable and added
// sender_admin_id (nullable, FK'd to company_admins) so either side can send;
// exactly one of the two is set per row. Existing rows keep their sender_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['sender_id']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('sender_id')->nullable()->change();
            $table->foreign('sender_id')->references('id')->on('users')->nullOnDelete();

            if (!Schema::hasColumn('chat_messages', 'sender_admin_id')) {
                $table->unsignedBigInteger('sender_admin_id')->nullable()->after('sender_id');
                $table->foreign('sender_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['sender_id']);
            if (Schema::hasColumn('chat_messages', 'sender_admin_id')) {
                $table->dropForeign(['sender_admin_id']);
                $table->dropColumn('sender_admin_id');
            }
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('sender_id')->nullable(false)->change();
            $table->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
