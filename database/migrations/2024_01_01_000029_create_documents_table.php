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
        if (Schema::hasTable('documents')) return;

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 255);
            $table->enum('type', ['pdf', 'spreadsheet', 'word', 'image', 'other'])->nullable();
            $table->string('file_path', 600)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->unsignedTinyInteger('version')->default(1);
            $table->unsignedBigInteger('parent_doc_id')->nullable();
            $table->string('linked_to_type', 50)->nullable();
            $table->unsignedBigInteger('linked_to_id')->nullable();
            $table->tinyInteger('is_shared')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_doc_id')->references('id')->on('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
