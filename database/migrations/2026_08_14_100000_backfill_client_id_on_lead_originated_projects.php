<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A project auto-created from a lead's paid invoice (see
// App\Services\PaymentProjectStartService::createDraftProject()) is stamped
// with lead_id only — client_id stays null since no Client exists yet at
// that point. Api\Admin\LeadController::convert() and
// Api\User\LeadController::convert() now backfill client_id the moment the
// lead converts, but any lead that already converted BEFORE that fix left
// its project(s) permanently invisible to the Client Portal (Api\Client\
// ProjectController filters strictly on client_id). This is the one-off
// catch-up for those already-converted leads — only ever fills a NULL
// client_id, never overwrites one that's already set.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('projects') || !Schema::hasTable('clients')) {
            return;
        }

        $clients = DB::table('clients')->whereNotNull('lead_id')->get(['id', 'lead_id']);

        foreach ($clients as $client) {
            DB::table('projects')
                ->where('lead_id', $client->lead_id)
                ->whereNull('client_id')
                ->update(['client_id' => $client->id]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};
