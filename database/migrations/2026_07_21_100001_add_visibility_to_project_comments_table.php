<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors chat_messages.visibility (2026_07_20_100001) exactly — distinguishes
// internal PM/team notes from client-facing comments within the SAME
// project_comments table, rather than a separate table/thread.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_comments', function (Blueprint $table) {
            if (!Schema::hasColumn('project_comments', 'visibility')) {
                $table->enum('visibility', ['internal', 'client'])->default('internal')->after('body');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_comments', function (Blueprint $table) {
            if (Schema::hasColumn('project_comments', 'visibility')) {
                $table->dropColumn('visibility');
            }
        });
    }
};
