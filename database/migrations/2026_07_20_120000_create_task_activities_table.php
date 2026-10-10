<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors lead_activities/LeadActivity/Lead::logActivity() exactly — same
// shape, same "causer_name as a plain string" pattern (works uniformly for
// both a sub-user actor and a Company Admin actor, neither of which needs a
// real users.id FK to be identified).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('causer_name', 100)->nullable();
            $table->enum('type', [
                'created', 'updated', 'status_changed', 'assigned', 'completed',
            ]);
            $table->text('description');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
    }
};
