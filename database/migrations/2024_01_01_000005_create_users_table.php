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
        if (Schema::hasTable('users')) return;

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->enum('role_type', ['seller', 'client', 'hr', 'finance', 'project_manager', 'production']);
            $table->string('phone', 30)->nullable();
            $table->string('avatar_path', 600)->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->string('socket_id', 100)->nullable();
            $table->boolean('is_online')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
