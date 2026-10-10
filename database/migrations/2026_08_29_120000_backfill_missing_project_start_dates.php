<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// App\Services\PaymentProjectStartService::createActiveProject() never set
// start_date on a project auto-created (or promoted from 'unpaid') by a paid
// invoice — every such project's "Start Date" field has sat permanently
// blank since. Now fixed going forward (both branches of createActiveProject()
// set it); this backfills every existing active/completed/closed project
// still missing one, using its created_at date as the best available
// approximation of when it actually started.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('projects')) {
            return;
        }

        DB::table('projects')
            ->whereNull('start_date')
            ->whereIn('status', ['active', 'completed', 'closed'])
            ->update(['start_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};
