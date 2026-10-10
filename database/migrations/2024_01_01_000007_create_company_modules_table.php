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
        if (Schema::hasTable('company_modules')) return;

        Schema::create('company_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('module_key', 60);
            $table->tinyInteger('is_enabled')->default(1);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['company_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_modules');
    }
};
