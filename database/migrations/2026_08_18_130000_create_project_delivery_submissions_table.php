<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A history log for the project-level "final package" handoff — separate
// from the `deliverables` table (task-level production QA submissions, a
// different feature entirely — see Deliverable model). Every time the final
// package moves to 'delivered_to_client' — whether via
// Admin\ProjectController::approveDelivery() (a PM's submission approved) or
// ::uploadAndDeliver() (Admin's own direct upload, skipping review) — a row
// is written here, so a project can be delivered more than once (e.g. a
// corrected package after the client reports an issue) without losing the
// earlier ones. projects.delivery_* columns still hold only the CURRENT/
// latest one, for the existing download/public-link code paths.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_delivery_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('file_path', 600);
            $table->string('file_name');
            $table->string('file_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            // Always an admin in practice — only Admin::approveDelivery()/
            // uploadAndDeliver() ever move a project to delivered_to_client —
            // nullable only for the same forward-compatibility reason every
            // other actor FK on this table is nullable elsewhere in the app.
            $table->foreignId('delivered_by_admin_id')->nullable()->constrained('company_admins')->nullOnDelete();
            $table->timestamp('delivered_at');
            $table->timestamps();

            $table->index(['project_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_delivery_submissions');
    }
};
