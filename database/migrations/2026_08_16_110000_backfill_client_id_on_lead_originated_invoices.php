<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// An invoice raised against a Lead before conversion (created via
// /invoices/new?lead_id=...) is stamped with lead_id only — client_id stays
// null since no Client exists yet at that point. Api\Admin\LeadController::
// convert() and Api\User\LeadController::convert() now backfill client_id the
// moment the lead converts (same fix already applied to Projects — see
// 2026_08_14_100000_backfill_client_id_on_lead_originated_projects.php), but
// any lead that already converted BEFORE that fix left its invoice(s)
// permanently invisible to the Client Portal (Api\Client\InvoiceController
// filters strictly on client_id) even after portal access was granted. This
// is the one-off catch-up for those already-converted leads — only ever
// fills a NULL client_id, never overwrites one that's already set.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices') || !Schema::hasTable('clients')) {
            return;
        }

        $clients = DB::table('clients')->whereNotNull('lead_id')->get(['id', 'lead_id']);

        foreach ($clients as $client) {
            DB::table('invoices')
                ->where('lead_id', $client->lead_id)
                ->whereNull('client_id')
                ->update(['client_id' => $client->id]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_08_14_100000's rationale.
    }
};
