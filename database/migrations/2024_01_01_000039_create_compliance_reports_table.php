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
        if (Schema::hasTable('compliance_reports')) return;

        Schema::create('compliance_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreignId('generated_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 255);
            $table->enum('report_type', ['policies', 'risks', 'incidents', 'violations', 'audit', 'summary'])->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('file_path', 600)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_reports');
    }
};
