<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained('project_comments')->cascadeOnDelete();
            // Exactly one of these is ever set per row (dual-guard actor, same
            // pattern as project_comments.author_admin_id/author_user_id).
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('company_admins')->cascadeOnDelete();
            $table->timestamps();

            // Two separate unique indexes (not one composite) so a user can't
            // like the same comment twice, and neither can an admin — MySQL
            // treats NULLs as distinct, so the "other side" being null never
            // blocks a legitimate row.
            $table->unique(['comment_id', 'user_id']);
            $table->unique(['comment_id', 'admin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_comment_likes');
    }
};
