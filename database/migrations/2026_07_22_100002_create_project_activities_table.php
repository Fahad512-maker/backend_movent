<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors task_activities/TaskActivity/Task::logActivity() exactly (itself
// mirroring lead_activities/LeadActivity) — a project-level timeline
// distinct from the generic company-wide SystemAuditLog, specifically for
// the new completion/close/reopen lifecycle events.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('causer_name', 100)->nullable();
            $table->enum('type', [
                'completed', 'close_blocked', 'closed', 'reopened',
            ]);
            $table->text('description');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activities');
    }
};
