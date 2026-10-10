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
        if (Schema::hasTable('package_modules')) return;

        Schema::create('package_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->string('module_key', 60);
            $table->tinyInteger('is_enabled')->default(1);
            $table->timestamps();
            $table->unique(['package_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_modules');
    }
};
