<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Widens tasks.status with the new QA/production pipeline stages — the
// original 5 values (todo, in_progress, review, completed, cancelled) stay
// legal so existing rows and the pre-existing Seller-linked-task "pending PM
// review" flow (status forced to 'review') keep working untouched.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM(
            'todo', 'in_progress', 'blocked', 'ready_for_qa', 'in_qa',
            'qa_failed', 'qa_passed', 'ready_for_production', 'in_production',
            'review', 'completed', 'cancelled'
        ) NOT NULL DEFAULT 'todo'");

        Schema::table('tasks', function (Blueprint $table) {
            // Persistent "last QA verdict" — survives the task moving on to
            // ready_for_production/in_production/completed, so QA history
            // isn't lost once status advances past the QA stage.
            if (!Schema::hasColumn('tasks', 'qa_status')) {
                $table->string('qa_status', 20)->nullable()->after('status');
            }
            if (!Schema::hasColumn('tasks', 'ready_for_qa_at')) {
                $table->timestamp('ready_for_qa_at')->nullable()->after('qa_status');
            }
            if (!Schema::hasColumn('tasks', 'qa_started_at')) {
                $table->timestamp('qa_started_at')->nullable()->after('ready_for_qa_at');
            }
            if (!Schema::hasColumn('tasks', 'qa_completed_at')) {
                $table->timestamp('qa_completed_at')->nullable()->after('qa_started_at');
            }
            if (!Schema::hasColumn('tasks', 'ready_for_production_at')) {
                $table->timestamp('ready_for_production_at')->nullable()->after('qa_completed_at');
            }
            // Split like Notification's actor_user_id/actor_admin_id — this
            // app has two disjoint actor id-spaces (User vs CompanyAdmin guard).
            if (!Schema::hasColumn('tasks', 'status_changed_by_user_id')) {
                $table->foreignId('status_changed_by_user_id')->nullable()->after('ready_for_production_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('tasks', 'status_changed_by_admin_id')) {
                $table->foreignId('status_changed_by_admin_id')->nullable()->after('status_changed_by_user_id')->constrained('company_admins')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            foreach (['status_changed_by_user_id', 'status_changed_by_admin_id'] as $col) {
                if (Schema::hasColumn('tasks', $col)) {
                    $table->dropConstrainedForeignId($col);
                }
            }
            foreach (['qa_status', 'ready_for_qa_at', 'qa_started_at', 'qa_completed_at', 'ready_for_production_at'] as $col) {
                if (Schema::hasColumn('tasks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM('todo', 'in_progress', 'review', 'completed', 'cancelled') NOT NULL DEFAULT 'todo'");
    }
};
