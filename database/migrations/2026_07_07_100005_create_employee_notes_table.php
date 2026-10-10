<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors project_comments' actor pattern, minus author_user_id — this phase
// is Admin-only, so only a Company Admin can ever author a note.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('author_admin_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->foreign('author_admin_id')->references('id')->on('company_admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_notes');
    }
};
