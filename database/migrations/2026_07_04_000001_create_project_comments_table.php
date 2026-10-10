<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->unsignedBigInteger('author_admin_id')->nullable();
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->foreign('author_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->foreign('author_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_comments');
    }
};
