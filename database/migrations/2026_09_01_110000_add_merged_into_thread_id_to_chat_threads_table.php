<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// When an invoice is paid, PaymentProjectStartService::migrateChatHistory()
// MOVES the pre-payment 'sales' thread's messages into the new project's
// thread — the conversation physically changes address. The public invoice
// link follows it (App\Services\InvoiceChatContext::resolve() switches to the
// project thread as soon as invoices.project_id is set) and so does the client
// portal, but the STAFF side never did: Api\User\SalesChatController and
// Api\Admin\SalesChatController always resolve thread_type='sales', so after
// payment a Seller/Lead Manager/Admin opened an empty Sales Chat and every
// reply they sent went to a thread the client no longer reads.
//
// This records the forwarding address at the moment of the move, so both sides
// converge on one conversation. A pointer rather than a lookup because the
// mapping genuinely cannot be recomputed later: a Client with several projects
// (invoice #2 and #3 both feeding project #2 while invoice #1 fed project #1 in
// the current data) gives no way to tell WHICH project a given sales thread
// drained into once its rows have moved.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_threads')) {
            return;
        }

        Schema::table('chat_threads', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_threads', 'merged_into_thread_id')) {
                $table->unsignedBigInteger('merged_into_thread_id')->nullable()->after('thread_type');
                $table->foreign('merged_into_thread_id')->references('id')->on('chat_threads')->nullOnDelete();
            }
        });

        $this->backfill();
    }

    // Existing threads were drained before the pointer existed. Their target is
    // recoverable through the invoice that created the project — but only where
    // it is UNAMBIGUOUS: exactly one project thread among all of this anchor's
    // invoices. Anything with two or more candidates is left null, so a
    // conversation is never silently attached to the wrong project.
    private function backfill(): void
    {
        foreach (['Lead' => 'lead_id', 'Client' => 'client_id'] as $linkedType => $invoiceColumn) {
            $threads = DB::table('chat_threads')
                ->where('thread_type', 'sales')
                ->where('linked_to_type', $linkedType)
                ->whereNull('merged_into_thread_id')
                ->select('id', 'linked_to_id')
                ->get();

            foreach ($threads as $thread) {
                // Only a DRAINED thread gets forwarded. One that still holds its
                // own messages was never migrated (no payment yet) and must keep
                // living where it is.
                if (DB::table('chat_messages')->where('thread_id', $thread->id)->exists()) {
                    continue;
                }

                $projectIds = DB::table('invoices')
                    ->where($invoiceColumn, $thread->linked_to_id)
                    ->whereNotNull('project_id')
                    ->distinct()
                    ->pluck('project_id');

                if ($projectIds->isEmpty()) {
                    continue;
                }

                $targets = DB::table('chat_threads')
                    ->where('thread_type', 'project_group')
                    ->where('linked_to_type', 'Project')
                    ->whereIn('linked_to_id', $projectIds)
                    ->pluck('id');

                if ($targets->count() === 1) {
                    DB::table('chat_threads')
                        ->where('id', $thread->id)
                        ->update(['merged_into_thread_id' => $targets->first()]);
                }
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('chat_threads')) {
            return;
        }

        Schema::table('chat_threads', function (Blueprint $table) {
            if (Schema::hasColumn('chat_threads', 'merged_into_thread_id')) {
                $table->dropForeign(['merged_into_thread_id']);
                $table->dropColumn('merged_into_thread_id');
            }
        });
    }
};
