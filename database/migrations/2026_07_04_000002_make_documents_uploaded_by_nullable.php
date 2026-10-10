<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// documents.uploaded_by was NOT NULL, FK'd only to `users` (staff). Company
// Admins uploading a project document have no `users` row to reference, so
// the column must accept null — mirrors the already-nullable `uploaded_by`
// on `deliverables`. Existing rows are untouched; only the constraint loosens.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('uploaded_by')->nullable()->change();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('uploaded_by')->nullable(false)->change();
            $table->foreign('uploaded_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
