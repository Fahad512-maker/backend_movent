<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compliance_template_requirements')) return;

        Schema::create('compliance_template_requirements', function (Blueprint $table) {
            $table->id();
            // Denormalized alongside template_id so a company-scoped listing
            // never needs to join through compliance_templates.
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('compliance_templates')->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('requires_expiry')->default(false);
            $table->unsignedInteger('default_validity_days')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['template_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_template_requirements');
    }
};
