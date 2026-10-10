<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What a Client asked for from the "Grow Your Business With Us" section.
//
// Three of the four client actions land here, separated by `intent` rather
// than by three near-identical tables: "Request Quote", "Start New Project"
// and "Schedule Consultation" differ in what the client wants next, not in
// what has to be recorded. The fourth action, "Contact Support", deliberately
// does NOT come here — it goes through the support ticket flow that already
// exists, because a support request belongs in the support queue.
//
// lead_id: every request also raises a Lead for the company, so it lands in
// the sales pipeline rather than in a list somebody has to remember to check.
// Nullable because the Lead is created after this row and because a company
// without the Sales module has no pipeline to raise one in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            // Nullable: "Other IT Services" and a request raised against a
            // service the admin later deleted both have to stay readable.
            $table->unsignedBigInteger('company_service_id')->nullable();
            // Kept alongside company_service_id so the request still says what
            // it was about after the service row is gone.
            $table->string('service_name', 150);
            $table->enum('intent', ['quote', 'new_project', 'consultation']);
            $table->text('message')->nullable();
            // Consultation only — when the client would like to talk.
            $table->date('preferred_date')->nullable();
            $table->enum('status', ['new', 'in_progress', 'closed'])->default('new');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->timestamps();

            $table->foreign('company_service_id')->references('id')->on('company_services')->nullOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();

            $table->index(['company_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
