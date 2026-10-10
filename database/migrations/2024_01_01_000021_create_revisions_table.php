<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some deployments' database was seeded from a raw SQL snapshot that
        // already included this table before migrations tracking began —
        // skip rather than error if it's already there.
        if (Schema::hasTable('revisions')) return;

        Schema::create('revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverable_id')->constrained('deliverables')->cascadeOnDelete();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->text('feedback')->nullable();
            $table->enum('status', ['open', 'in_progress', 'resolved'])->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
    }
};
