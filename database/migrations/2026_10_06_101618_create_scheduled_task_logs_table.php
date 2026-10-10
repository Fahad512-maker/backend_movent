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
        // Platform-wide ops record of every Schedule::command() run in
        // routes/console.php — intentionally separate from system_audit_logs
        // (company_id/user_id-scoped business activity for Company Admins)
        // since a cron run belongs to no company and no user; this is for
        // Super Admin to see whether the scheduler is actually healthy.
        Schema::create('scheduled_task_logs', function (Blueprint $table) {
            $table->id();
            $table->string('command'); // e.g. 'invoices:mark-overdue' — the artisan signature, not a label
            $table->enum('status', ['success', 'failed']);
            $table->unsignedInteger('exit_code')->nullable();
            $table->text('output')->nullable(); // this run's own stdout+stderr only, never a running append
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['command', 'ran_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_logs');
    }
};
