<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->enum('type', ['call', 'email', 'meeting', 'whatsapp', 'demo', 'other'])->default('call');
            $table->dateTime('scheduled_at');
            $table->dateTime('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'completed', 'missed', 'cancelled'])->default('pending');
            $table->boolean('reminder_enabled')->default(false);
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'status']);
            $table->index(['lead_id', 'status']);
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
