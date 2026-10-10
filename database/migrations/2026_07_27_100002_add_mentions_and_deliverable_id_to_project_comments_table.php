<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds @mention support and lets a comment optionally scope to a Deliverable
// (in addition to the existing optional task_id) — mirrors the additive-
// column precedent already used for `visibility` on this same table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_comments', function (Blueprint $table) {
            if (!Schema::hasColumn('project_comments', 'mentions')) {
                $table->json('mentions')->nullable()->after('body');
            }
            if (!Schema::hasColumn('project_comments', 'deliverable_id')) {
                $table->foreignId('deliverable_id')->nullable()->after('task_id')
                    ->constrained('deliverables')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_comments', function (Blueprint $table) {
            if (Schema::hasColumn('project_comments', 'deliverable_id')) {
                $table->dropConstrainedForeignId('deliverable_id');
            }
            if (Schema::hasColumn('project_comments', 'mentions')) {
                $table->dropColumn('mentions');
            }
        });
    }
};
