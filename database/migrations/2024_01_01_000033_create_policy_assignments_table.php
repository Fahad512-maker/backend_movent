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
        if (Schema::hasTable('policy_assignments')) return;

        Schema::create('policy_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('compliance_policies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->tinyInteger('is_acknowledged')->default(0);
            $table->timestamp('acknowledged_at')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['policy_id', 'user_id']);

            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_assignments');
    }
};
