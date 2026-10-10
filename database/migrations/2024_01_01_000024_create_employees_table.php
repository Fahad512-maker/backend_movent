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
        if (Schema::hasTable('employees')) return;

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('employee_code', 50)->unique()->nullable();
            $table->string('name', 150);
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('designation', 100)->nullable();
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'intern'])->default('full_time');
            $table->decimal('salary', 12, 2)->nullable();
            $table->date('join_date')->nullable();
            $table->enum('status', ['active', 'on_leave', 'terminated'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
