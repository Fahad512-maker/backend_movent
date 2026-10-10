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
        if (Schema::hasTable('packages')) return;

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->enum('tier', ['basic', 'professional', 'enterprise', 'custom']);
            $table->decimal('price', 10, 2);
            $table->enum('billing_cycle', ['monthly', 'yearly'])->nullable();
            $table->integer('max_companies')->nullable();
            $table->integer('max_users_per_company')->nullable();
            $table->text('description')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_visible')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
