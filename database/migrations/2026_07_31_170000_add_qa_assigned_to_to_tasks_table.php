<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets the person moving a task to "Ready for QA" hand it off to a specific
// QA user, instead of just broadcasting to every QA-role team member on the
// project — separate from `assigned_to` (the developer/production owner),
// since a task keeps its original assignee throughout the QA back-and-forth.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'qa_assigned_to')) {
                $table->foreignId('qa_assigned_to')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'qa_assigned_to')) {
                $table->dropConstrainedForeignId('qa_assigned_to');
            }
        });
    }
};
