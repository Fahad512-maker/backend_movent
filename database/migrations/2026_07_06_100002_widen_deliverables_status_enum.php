<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds 'submitted' and 'rejected' to deliverables.status so upload → review →
// approve/reject/revision can be represented distinctly. Purely additive —
// all 4 existing enum values are kept, so no existing row or consumer breaks.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE deliverables MODIFY status ENUM('draft','delivered','approved','revision_requested','submitted','rejected') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE deliverables MODIFY status ENUM('draft','delivered','approved','revision_requested') NOT NULL DEFAULT 'draft'");
    }
};
