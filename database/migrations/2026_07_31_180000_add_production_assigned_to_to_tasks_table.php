<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional Production/Deployment handoff — mirrors qa_assigned_to's shape
// but stays nullable-and-optional (not required) when a task moves to
// "Ready for Production": if set, only this user gets notified instead of
// the whole project's production_user-role team.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'production_assigned_to')) {
                $table->foreignId('production_assigned_to')->nullable()->after('qa_assigned_to')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'production_assigned_to')) {
                $table->dropConstrainedForeignId('production_assigned_to');
            }
        });
    }
};
