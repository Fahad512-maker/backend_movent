<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// "Visible to client" is no longer a manual per-file toggle — every project
// attachment is visible to the client now. Backfill the rows that predate
// this so nothing uploaded earlier stays silently hidden.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_attachments')->where('is_visible_to_client', false)->update(['is_visible_to_client' => true]);
    }

    public function down(): void
    {
        // Not reversible — the per-file hidden/visible state before this
        // migration ran is not recoverable.
    }
};
