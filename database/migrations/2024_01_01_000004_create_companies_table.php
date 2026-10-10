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
        if (Schema::hasTable('companies')) return;

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('company_admins')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('industry', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 60)->default('Asia/Karachi');
            $table->string('currency', 10)->default('PKR');
            $table->string('logo_path', 600)->nullable();
            $table->string('storage_folder', 300)->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
